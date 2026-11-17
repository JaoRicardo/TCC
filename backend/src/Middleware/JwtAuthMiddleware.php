<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\JwtService;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthMiddleware implements MiddlewareInterface
{
    /**
     * O que a pessoa com senha provisória ainda pode fazer: criar a senha
     * própria, ler os próprios dados (a tela precisa do nome) e sair.
     */
    private const LIBERADO_COM_SENHA_PROVISORIA = [
        'POST /api/v1/users/me/senha',
        'GET /api/v1/users/me',
        'GET /api/v1/auth/me',
        'POST /api/v1/auth/logout',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $payload = JwtService::decode($token);

            if ($payload !== null && ($payload->type ?? '') === 'access') {
                $user = TableRegistry::getTableLocator()
                    ->get('Users')
                    ->find()
                    ->where(['id' => $payload->sub, 'deleted IS' => null])
                    ->first();

                // Login feito antes da senha provisória não vale mais.
                if ($user && $user->temporary_password_at !== null
                    && (int)($payload->iat ?? 0) < $user->temporary_password_at->getTimestamp()) {
                    $user = null;
                }

                if ($user) {
                    $request = $request->withAttribute('identity', $user);

                    if ($user->must_change_password && !$this->liberadoComSenhaProvisoria($request)) {
                        return (new Response())
                            ->withStatus(403)
                            ->withType('application/json')
                            ->withStringBody((string)json_encode([
                                'error' => 'Crie a sua senha antes de continuar.',
                                'trocar_senha' => true,
                            ], JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }

        return $handler->handle($request);
    }

    private function liberadoComSenhaProvisoria(ServerRequestInterface $request): bool
    {
        $rota = $request->getMethod() . ' ' . rtrim($request->getUri()->getPath(), '/');

        return in_array($rota, self::LIBERADO_COM_SENHA_PROVISORIA, true);
    }
}
