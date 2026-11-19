<?php
use SLiMS\Error;

if (!function_exists('getallheaders')) {
    function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    return $headers;
    }
}


if (!function_exists('slimsShutdownHandler'))
{
    function slimsShutdownHandler()
    {
        $last_error = error_get_last();

		if (isset($last_error) &&
			($last_error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING)))
		{
			slimsErrorHandler($last_error['type'], $last_error['message'], $last_error['file'], $last_error['line']);
		}
    }
}

if (!function_exists('slimsExceptionHandler'))
{
    function slimsExceptionHandler($exception)
    {
        Error::set([
            'type' => 'exception',
            'message' => $exception->getMessage(),
            'path' => $exception->getFile(),
            'line' => $exception->getLine(),
            'class' => $exception::class,
        ], $exception->getTrace())->render()->send(inEnv: ENVIRONMENT);
    }
}

if (!function_exists('slimsErrorHandler'))
{
    function slimsErrorHandler($severity, $message, $filepath, $line)
    {
        // Respect the @ operator and the configured error_reporting level.
        // Without this, every warning that the code deliberately silences with @
        // (e.g. @unserialize(), @$dbs->query()) aborted the page with an error
        // screen / HTTP 500, in production too. Fatal errors that reach the
        // shutdown handler are not affected.
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $is_error = (((E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR | E_WARNING) & $severity) === $severity);

        if ($is_error) {
            Error::set([
                'type' => 'error',
                'severity' => $severity,
                'message' => $message,
                'path' => $filepath,
                'line' => $line
            ], debug_backtrace())->render()->send(inEnv: ENVIRONMENT);
        }
    }
}

if (!function_exists('registerSlimsHandler'))
{
    function registerSlimsHandler()
    {
        set_error_handler('slimsErrorHandler');
        set_exception_handler('slimsExceptionHandler');
        register_shutdown_function('slimsShutdownHandler');
    }
}