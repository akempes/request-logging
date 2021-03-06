<?php

namespace Akempes\RequestLogging;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class LogRequest
{

    public $startedAt;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $this->startedAt = $this->microtime_float();

        if (
            config('request-logging.enabled', false) &&
            in_array($request->getMethod(), config('request-logging.methods', [])) &&
            !$this->inExceptArray($request)
        ) {

            $this->logRequest($request);

            $response = $next($request);

            $this->logResponse($response, $request);

            return $response;
        }

        return $next($request);
    }

    private function logRequest(Request $request)
    {
        $method = strtoupper($request->getMethod());

        $uri = $request->getPathInfo();

        $bodyAsJson = json_encode($request->except(config('request-logging.exclude-request-fields', ['password', 'password_confirmation'])));

        $files = collect(iterator_to_array($request->files))
            ->map(function ($file)
            {
                return $this->flattenFiles($file);
            })
            ->flatten()
            ->implode(',')
        ;

        $message = '#' . Str::after($this->startedAt, '.') . " {$method} {$uri} - Body: {$bodyAsJson} - Files: ". $files;

        $this->writeMessage($message);
    }

    private function logResponse($response, Request $request)
    {
        $duration = ($this->microtime_float() - $this->startedAt) * 1000;

        $durationLimit = config('request-logging.request-duration-limit', false);
        if($durationLimit && $duration > $durationLimit) {
            \Log::warning('Request exceeded response duration threshold. It took ' . $duration . 'ms to respond to ' . $request->getPathInfo() );
        }

        $status = strtoupper($response->getStatusCode());

        $bodyAsJson = $request->expectsJson() ? json_encode(Arr::except(json_decode($response->getContent(), true), config('request-logging.exclude-response-fields', []))) : (config('request-logging.show-response-html', false) ? $response->getContent() : 'Non-JSON content returned');

        $message = '#' . Str::after($this->startedAt, '.') . " {$status} - Duration: {$duration}ms - Body: {$bodyAsJson}";

        $this->writeMessage($message);
    }

    public function flattenFiles($file)
    {
        if ($file instanceof UploadedFile) {
            return $file->getClientOriginalName();
        }

        if (is_array($file)) {
            return collect($file)->map(function ($file)
            {
                return $this->flattenFiles($file);
            });
        }

        return (string) $file;
    }

    private function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    private function writeMessage($message)
    {
        Log::stack(config('request-logging.log-channels', []))->info($message);
    }

    private function inExceptArray($request)
    {
        foreach (config('request-logging.exclude-routes', []) as $route) {
            if ($route !== '/') {
                $route = trim($route, '/');
            }

            if ($request->is($route)) {
                return true;
            }
        }

        return false;
    }

}
