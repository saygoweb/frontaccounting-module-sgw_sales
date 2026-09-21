<?php

namespace SGW_Sales\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * A logged-in FrontAccounting session over HTTP, at FA_URL.
 */
abstract class HttpTestCase extends TestCase
{
    /** @var string */
    private static $base = '';

    /**
     * The session cookie, carried by hand. FrontAccounting marks it `secure`
     * whatever the scheme (SECURE_ONLY in includes/session.inc) and lets plain
     * http through for localhost only; a cookie jar would not send it back.
     * @var array<string, string>
     */
    private static $cookies = [];

    protected function setUp(): void
    {
        self::$base = rtrim((string) getenv('FA_URL'), '/');
        if (!self::$base) {
            $this->markTestSkipped('FA_URL is not set - run the suite through docker/fa-sgw-sales test');
        }
        if (!self::$cookies) {
            $this->login();
        }
    }

    private function login(): void
    {
        [, $html] = $this->request('/index.php');
        $this->request('/index.php', [
            'user_name_entry_field' => getenv('FA_USER') ?: 'admin',
            'password' => getenv('FA_PASSWORD') ?: 'password',
            'company_login_name' => '0',
            'ui_mode' => '',
            'SubmitUser' => 'Login',
            '_token' => $this->token($html),
        ]);
    }

    /** The CSRF token FrontAccounting puts in every form and checks on every POST. */
    protected function token(string $html): string
    {
        if (!preg_match('/name="_token" value="([^"]*)"/', $html, $m)) {
            $this->fail('no form token in the page');
        }
        return $m[1];
    }

    /** @return array{0: int, 1: string} status and body */
    protected function request(string $path, ?array $post = null): array
    {
        $c = curl_init(self::$base . $path);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            // Not optional: FrontAccounting's SessionManager::preventHijacking()
            // discards any session that was started without a user agent.
            CURLOPT_USERAGENT => 'sgw_sales-tests',
            CURLOPT_COOKIE => http_build_query(self::$cookies, '', '; '),
            CURLOPT_HEADERFUNCTION => function ($c, $header) {
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/i', $header, $m)) {
                    self::$cookies[$m[1]] = urldecode($m[2]);
                }
                return strlen($header);
            },
        ]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return [$status, (string) $body];
    }

    protected function assertRendered(int $status, string $html): void
    {
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('name="user_name_entry_field"', $html, 'got the login form: the login did not take');
        $this->assertDoesNotMatchRegularExpression('/Fatal error|Uncaught|DATABASE ERROR|class=.err_msg/i', $html);
    }
}
