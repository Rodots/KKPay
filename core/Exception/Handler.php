<?php

namespace Core\Exception;

use Core\Utils\TraceIDUtil;
use RuntimeException;
use SplFileObject;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 自定义异常处理类
 */
class Handler extends ExceptionHandler
{
    /**
     * 获取指定文件中某行号周围的代码行
     *
     * @param string $filename   文件路径
     * @param int    $lineNumber 行号
     * @param int    $range      范围，默认前后10行
     * @return array
     */
    protected function getLinesAround(string $filename, int $lineNumber, int $range = 10): array
    {
        if (!is_readable($filename)) {
            throw new RuntimeException("Unable to read file: $filename");
        }

        $file      = new SplFileObject($filename);
        $startLine = max(1, $lineNumber - $range);
        $endLine   = $lineNumber + $range;
        $lines     = [];

        $file->seek($startLine - 1); // SplFileObject 索引从 0 开始

        while ($file->valid() && $file->key() < $endLine) {
            $currentLine = $file->key() + 1;
            $lineContent = $file->current();

            $lines[] = [
                'line'    => $currentLine,
                'content' => $lineContent
            ];

            $file->next();
        }

        return $lines;
    }

    /**
     * 渲染异常响应
     *
     * @param Request   $request
     * @param Throwable $exception
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response
    {
        $code = $exception->getCode() ?: 50000;

        if ($request->expectsJson()) {
            if (!is_int($code)) {
                $code = 50000;
            }
            return $this->jsonResponse($code, $exception);
        }

        if ($this->debug) {
            return $this->renderDebugPage($exception);
        }

        return $this->jsonResponse(50000, $exception);
    }

    /**
     * 返回 JSON 格式响应
     *
     * @param int       $code
     * @param Throwable $exception
     * @return Response
     */
    protected function jsonResponse(int $code, Throwable $exception): Response
    {
        $message = $this->debug ? $exception->getMessage() : '服务器内部错误';

        $result = [
            'code'    => $code,
            'data'    => [],
            'message' => $message,
            'state'   => false
        ];

        if ($trace_id = TraceIDUtil::getTraceID()) {
            $result['trace_id'] = $trace_id;
        }

        $resCode = $exception->getCode() ?: 500;

        return new Response(
            $resCode,
            ['Content-Type' => 'application/json'],
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * 渲染调试页面
     *
     * @param Throwable $exception
     * @return Response
     */
    protected function renderDebugPage(Throwable $exception): Response
    {
        $tpl = __DIR__ . '/template.html';

        $lines = $this->getLinesAround(
            $exception->getFile(),
            $exception->getLine()
        );

        $error_code = '';
        foreach ($lines as $line) {
            $class      = $line['line'] === $exception->getLine() ? 'highlight' : '';
            $error_code .= sprintf(
                '<div class="code-line %s"><div class="line-number">%d</div><div class="line-content">%s</div></div>',
                $class,
                $line['line'],
                htmlspecialchars($line['content'])
            );
        }

        $vars = [
            'error_file' => $exception->getFile() . '  line:' . $exception->getLine(),
            'error_msg'  => $exception->getMessage(),
            'error_code' => $error_code
        ];

        $resCode = $exception->getCode() ?: 500;

        return $this->renderTemplate($tpl, $vars, $resCode);
    }

    /**
     * 渲染模板文件
     *
     * @param string $path
     * @param array  $vars
     * @param int    $code
     * @return Response
     */
    protected function renderTemplate(string $path, array $vars, int $code = 500): Response
    {
        extract($vars);
        ob_start();
        require $path;
        $body = ob_get_clean();

        return new Response($code, ['Content-Type' => 'text/html'], $body);
    }
}
