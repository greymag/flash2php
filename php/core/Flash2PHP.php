<?php
require_once( F2P_ROOT . 'core/exceptions/F2PException.php' );

/**
 * 
 * @version 0.6
 * @author GreyMag <greymag@gmail.com>
 *
 */
class Flash2PHP
{
    protected $_serviceName;
    protected $_methodName;
    protected $_params; 
    protected $_service;
    /**
     * 
     * @var ReflectionMethod
     */
    protected $_method;
    
    protected $_servicesDir;
    
    private $_serviceParam;
    private $_methodParam;
    private $_paramsParam;
    
    /**
     * Логи для записи
     * @var array <code>Array of String</code>
     */
    private $_log = array();
    
    function __construct()
    {
        $this->_servicesDir = F2P_ROOT . F2P_SERVICES_PATH;
        if( substr( $this->_servicesDir, -1, 1 ) != '/' ) $this->_servicesDir .= '/';
        
        if (F2P_USE_SHORT_NAMES) {
            $this->_serviceParam = 's';
            $this->_methodParam  = 'm';
            $this->_paramsParam  = 'p';
        } else {
            $this->_serviceParam = 'service';
            $this->_methodParam  = 'method';
            $this->_paramsParam  = 'params';
        }

        set_exception_handler(array($this, 'simpleException'));
        set_error_handler(array($this, 'errorHandler'));
    }
    
    function __destruct() {
        if ($this->isUseLog()) {
            // проверяем - если такой директории нет,
            // то пытаемся создать
            $logFilePath = dirname( F2P_LOG_FILE );
            if (!file_exists( $logFilePath ) || !is_dir( $logFilePath )) {
                if (!@mkdir( $logFilePath, 0777, true )) return;
            }        
            if (!$logFile = @fopen( F2P_LOG_FILE, 'a' )) return;
            
            if (isset( $_SESSION )) 
               $this->_log[] = 'Session: ' . json_encode( $_SESSION );
            
            fwrite( 
               $logFile, 
               date( 'd.m.Y H:i:s' ) . "\n" .
               implode( "\n", $this->_log ) . "\n\n"
            );
            fclose( $logFile );
        }
    }
    
    public function init( $request )
    {       
        /*if ($this->isUseLog()) {
            $this->_log[0] = print_r( $request, true );
        }*/
        
        // пришел сжатый запрос 
        if (isset($request['z'])) 
        {
            if (!function_exists( 'gzuncompress' )) {
                if ($this->isUseLog()) {
                    $this->_log[0] = 'Request: ' . $request['z'];
                }
                throw new F2PException( 'Can\'t uncompress request' , 
                                        F2PException::ERRNO_COMPRESS_UNAVAILABLE );
            }
            
            $requestStr = gzuncompress( 
                              base64_decode( str_replace( ' ', '+', urldecode( $request['z'] ) ) ) 
                          );
            parse_str( $requestStr, $request );                     
        }
        
        if ($this->isUseLog()) 
        {
            $this->_log[0] = 'Request: ' . @json_encode( $request );
        }
        
        if (!isset( $request[$this->_serviceParam] )) 
            throw new F2PException( 'Service name not defined', 
                                    F2PException::ERRNO_UNKNOWN_SERVICE );
        if (!isset( $request[$this->_methodParam ] )) 
            throw new F2PException( 'Method name not defined', 
                                    F2PException::ERRNO_UNKNOWN_METHOD );
        
        $this->_serviceName = $request[$this->_serviceParam];
        $this->_methodName  = $request[$this->_methodParam ];
               
        $packages = explode( '.', $this->_serviceName );
        if (count( $packages ) > 1) {
            $this->_serviceName = array_pop( $packages );           
            $this->_servicesDir .= implode( '/', $packages ) . '/';
        }
        
        // ищем и подключаем файл с сервисом, если найдём
        // это для имени типа ИМЯ_КЛАССА(.*).php
        // теперь будем делать проще - зададим формат жёстко
        /*$services = scandir( $this->_servicesDir );
        foreach( $services as $serviceFile ) {
            $nameParts = explode( '.', $serviceFile );
            $count = count( $nameParts );
            if( $count > 1 && $nameParts[0] == $this->_serviceName && $nameParts[$count - 1] == 'php' ) {
                include_once( $this->_servicesDir . $serviceFile );
                break;
            }
        }*/
        // Ищем файл по имени, но разрешаем некоторые префиксы в порядке приоритета
        $attempt = 0;
        $suffix  = array('', 'Service');
        do
        {
            $serviceName = $this->_serviceName . $suffix[$attempt];
            $serviceFile = $this->_servicesDir . $serviceName . '.php';        
        } while ((!file_exists($serviceFile) || !is_file($serviceFile)) && 
                    ++$attempt < count($suffix));

        if ($attempt >= count($suffix))
            throw new F2PException( 'Service file not found', F2PException::ERRNO_UNKNOWN_SERVICE );

        $this->_serviceName = $serviceName;

        // Подключаем конфигурационный класс, если он определен
        if (F2P_PROJECT_CONFIG !== '' && file_exists(F2P_PROJECT_CONFIG))
        {
            include_once(F2P_PROJECT_CONFIG);
        }

        // Подключаем файл с классом сервиса
        include_once( $serviceFile );
        
        if (!class_exists( $this->_serviceName )) 
            throw new F2PException( 'Service not exist or not load', F2PException::ERRNO_UNKNOWN_SERVICE );

        // Создаем экземпляр сервиса
        $this->_service = new $this->_serviceName;

        if (!method_exists( $this->_service, $this->_methodName )) 
            throw new F2PException( 'Method not exist in this service', F2PException::ERRNO_UNKNOWN_METHOD );
        
        $this->_method = new ReflectionMethod( $this->_serviceName, $this->_methodName );
        
        $args = $this->_method->getParameters();
        
        $this->_params = (!isset( $request[$this->_paramsParam] )) 
                        ? array() 
                        : json_decode( /*stripcslashes(*/ $request[$this->_paramsParam] /*)*/ );
        if (!is_array( $this->_params )) 
            throw new F2PException( 'Wrong params', F2PException::ERRNO_WRONG_PARAMS );

        $countOptional = 0;
        foreach ($args as $param) {
            if ($param->isOptional()) $countOptional++;
        }
        
        $countParams = count( $this->_params );
        $countArgs   = count( $args );
        if ($countParams < $countArgs - $countOptional || $countParams > $countArgs) 
            throw new F2PException( 'Wrong number of arguments', F2PException::ERRNO_WRONG_NUM_ARGS );
         
        if (method_exists( $this->_service, 'beforeFilter' )) {
            try {
                if (!/*@*/$this->_service->beforeFilter( $this->_methodName )) throw new Exception();
            } catch (Exception $e) {
                throw new F2PException( 'Method call blocked by beforeFilter', F2PException::ERRNO_AUTH_BLOCKED ); 
            }
        }
        
        if ($this->isUseLog()) {
            $_r = array_merge( $request, array() );
            $_r[$this->_paramsParam] = $this->_params;
            $this->_log[0] = 'Request: ' . @json_encode( $_r );
            unset( $_r );
        }
        /*if ($this->isUseLog()) {
            $this->_log[] = 'Service: ' . $this->_serviceName;
            $this->_log[] = 'Method: ' . $this->_methodName;
            if (isset( $request[$this->_paramsParam] )) 
               $this->_log[] = 'Params: ' . $request[$this->_paramsParam];
        }*/
    }
    
    public function execute()
    {
        try {           
            $result = $this->_method->invokeArgs( $this->_service, $this->_params );
            // если вернули null, то ошибка
            if ($result === null) 
                $this->error( 'Null method return', F2PException::ERRNO_NULL_RETURN ); 
            else $this->answer( $result );
        }
        catch (ReflectionException $e) {
            $this->error( 'Call method error', F2PException::ERRNO_CALL_METHOD_ERROR );
        } 
        catch (Exception $e) {
            $this->error( 'Execute method error' . 
                          ($e->getMessage() != '' ? ': ' . $e->getMessage() : ''), 
                          F2PException::ERRNO_EXECUTE_METHOD_ERROR );
        }
    }
    
    public function error( $error, $errno = 0, $stack = false )
    {
        $this->answer( $this->generateError( $error, $errno, $stack ) );
    }
    
    public function exception( F2PException $e )
    {
        $this->error( $e->error, $e->errno );
    }
    
    public function simpleException( Exception $e )
    {
        $this->error( $e->getMessage(), $e->getCode() );
    }

    public function errorHandler($errno, $errstr, $errfile = null, $errline = null, $errcontext = null)
    {
        if (!(error_reporting() & $errno)) 
        {
            // Этот код ошибки не включен в error_reporting
            return true;
        }

        $message = '';

        switch ($errno) 
        {
            case E_ERROR        :
            case E_USER_ERROR   : $message .= 'Error'; break;
            case E_WARNING      :
            case E_USER_WARNING : $message .= 'Warning'; break;
            case E_NOTICE       : 
            case E_USER_NOTICE  : $message .= 'Notice'; break;
            default             : $message .= 'Unknown #' . $errno; break;
        }

        $message .= ': ' . $errstr;
        if ($errfile !== null)
        {
            $message .= ' [' . $errfile;
            if ($errline !== null) $message .= ':' . $errline;
            $message .= ']';
        }

        $this->error($message, F2PException::ERRNO_EXECUTE_METHOD_ERROR, F2P_DEBUG_MODE);
        die();
    }

    /**
     * Возвращает сохраненный лог
     * @return array Массив лога:
     * 0 - запрос
     * 1 - ответ
     */
    public function getLog() {
        return $this->_log;
    } 

    /**
     * Возвращает имя текущего сервиса
     * @return string
     */
    public function getServiceName() {
        return $this->_serviceName;
    }

    /**
     * Возвращает имя текущего метода
     * @return string
     */
    public function getMethodName() {
        return $this->_methodName;
    }
    
    protected function answer( $obj )
    {
        $answer = json_encode( $obj );
        
        if ($this->isUseLog()) {
            $this->_log[] = 'Answer: ' . $answer;
        }
        
        //$answer .= strlen( $answer );
        if (F2P_USE_COMPRESS && strlen( $answer ) > 40) {           
            if (function_exists( 'gzencode' )) 
            {
                header( "Content-Encoding: gzip" ); 
                $answer = gzencode( $answer );
            }
            else 
            {
                /*if( F2P_D EBUG_MODE ) throw new F2PException( 'Compress could not be used. Check gzip lib for PHP' );*/
                $answer = json_encode( $this->generateError( 'Compress could not be used. Check gzip lib for PHP or disable F2P_USE_COMPRESS option' ) );
                if ($this->isUseLog()) {
                    $this->_log[count( $this->_log ) - 1] = 'Answer: ' . $answer;
                }
            }
        }
        
        echo $answer;
    }
    
    private function generateError( $error, $errno, $stack )
    {
        $obj = array( 'error' => $error );
        if ($errno > 0) $obj['errno'] = $errno;
        if ($stack) 
        {
            $stack      = debug_backtrace();
            $errHdlrs   = array('generateError', 'error', 'errorHandler', 
                                'exception', 'simpleException');

            // Убираем из стека вызовы обработчиков ошибок в этом классе
            while (!empty($stack[0]) && !empty($stack[0]['class']) 
                    && $stack[0]['class'] === __CLASS__ && !empty($stack[0]['function'])
                    && in_array($stack[0]['function'], $errHdlrs)) 
            {
                array_shift($stack);
            }

            $obj['callStack'] = $stack;
        } 
        
        return $obj;
    }
    
    private function isUseLog() {
        return F2P_DEBUG_MODE && F2P_LOG_FILE != '';
    }
        
} 
?>