<?php

declare(strict_types=1);

namespace Core\Exception;

use Core\Utils\TraceIDUtil;
use SplFileObject;
use Throwable;
use Webman\Exception\BusinessException;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 自定义异常处理类
 *
 * 负责统一渲染异常响应，支持 JSON 与 HTML 调试页面两种输出模式。
 */
class Handler extends ExceptionHandler
{
    /** @var string[] 不需要上报的异常类型 */
    public $dontReport = [
        BusinessException::class,
    ];

    /** @var int 代码上下文显示行数（上下各取该值行） */
    private const int CONTEXT_LINES = 12;

    /**
     * 渲染异常响应
     *
     * JSON 请求返回 JSON，非 JSON 请求在调试模式下渲染 HTML 调试页，否则降级为 JSON。
     *
     * @param Request   $request   当前请求
     * @param Throwable $exception 捕获的异常
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response
    {
        $code = $this->safeCode($exception);

        if ($request->expectsJson()) {
            return $this->jsonResponse($code, $exception);
        }

        return $this->debug ? $this->renderDebugPage($exception, $code) : $this->jsonResponse(50000, $exception);
    }

    /**
     * 返回 JSON 格式响应
     *
     * @param int       $code      业务错误码
     * @param Throwable $exception 异常实例
     * @return Response
     */
    private function jsonResponse(int $code, Throwable $exception): Response
    {
        $result = [
            'code'    => $code,
            'message' => $this->debug ? $exception->getMessage() : '服务器内部错误',
            'state'   => false,
            ...($traceId = TraceIDUtil::getTraceID()) ? ['trace_id' => $traceId] : [],
        ];

        return new Response(
            $this->safeHttpStatus($exception),
            ['Content-Type' => 'application/json'],
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * 渲染 HTML 调试页面
     *
     * @param Throwable $exception 异常实例
     * @param int       $code      业务错误码
     * @return Response
     */
    private function renderDebugPage(Throwable $exception, int $code): Response
    {
        $lines = $this->getLinesAround($exception->getFile(), $exception->getLine());

        $error_code_html = implode('', array_map(
            fn(array $l): string => sprintf(
                '<div class="code-line %s"><div class="line-number">%d</div><div class="line-content">%s</div></div>',
                $l['line'] === $exception->getLine() ? 'highlight' : '',
                $l['line'],
                htmlspecialchars($l['content']),
            ),
            $lines,
        ));

        $vars = [
            'error_file'      => $exception->getFile() . '  line:' . $exception->getLine(),
            'error_msg'       => $exception->getMessage(),
            'error_code_html' => $error_code_html,
            'response_code'   => $code,
        ];

        return $this->renderTemplate(__DIR__ . '/template.html', $vars, $this->safeHttpStatus($exception));
    }

    /**
     * 获取指定文件中某行号周围的代码行
     *
     * @param string $filename   文件路径
     * @param int    $lineNumber 目标行号
     * @return array<int,array{line:int,content:string}>
     */
    private function getLinesAround(string $filename, int $lineNumber): array
    {
        $file      = new SplFileObject($filename);
        $startLine = max(1, $lineNumber - self::CONTEXT_LINES);
        $endLine   = $lineNumber + self::CONTEXT_LINES;
        $lines     = [];

        $file->seek($startLine - 1);

        while ($file->valid() && $file->key() < $endLine) {
            $lines[] = ['line' => $file->key() + 1, 'content' => $file->current()];
            $file->next();
        }

        return $lines;
    }

    /**
     * 渲染模板文件并返回 Response
     *
     * @param string $path 模板绝对路径
     * @param array  $vars 注入变量
     * @param int    $code HTTP 状态码
     * @return Response
     */
    protected function renderTemplate(string $path, array $vars, int $code = 500): Response
    {
        extract($vars);
        ob_start();
        require $path;

        return new Response($code, ['Content-Type' => 'text/html'], ob_get_clean());
    }

    /**
     * 安全获取业务错误码（非整数时降级为 50000）
     *
     * @param Throwable $exception 异常实例
     * @return int
     */
    private function safeCode(Throwable $exception): int
    {
        $code = $exception->getCode();
        return is_int($code) && $code > 0 ? $code : 50000;
    }

    /**
     * 安全获取 HTTP 状态码（非整数或非法值时降级为 500）
     *
     * @param Throwable $exception 异常实例
     * @return int
     */
    private function safeHttpStatus(Throwable $exception): int
    {
        $code = $exception->getCode();
        return is_int($code) && $code >= 100 && $code < 600 ? $code : 500;
    }
}
