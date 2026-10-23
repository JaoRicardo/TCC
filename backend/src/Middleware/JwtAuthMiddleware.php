<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\JwtService;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthMiddleware implements MiddlewareInterface
{
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

                if ($user) {
                    $request = $request->withAttribute('identity', $user);
                }
            }
        }

        return $handler->handle($request);
    }
}
