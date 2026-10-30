<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege a API JSON com uma chave fixa.
 * Uso: header "X-Api-Key: <chave>" ou ?api_key=<chave>.
 *
 * Leitura (api.key): VIX_API_KEY ou VIX_API_WRITE_KEY.
 * Escrita (api.key:escrita): só VIX_API_WRITE_KEY, e só pelo header — chave
 * de escrita em querystring acaba em log de acesso.
 */
class ApiKey
{
    public function handle(Request $request, Closure $next, string $nivel = 'leitura'): Response
    {
        $escrita = (string) config('vix.api_write_key');

        if ($nivel === 'escrita') {
            $given = (string) $request->header('X-Api-Key');
            $ok = $escrita !== '' && hash_equals($escrita, $given);
        } else {
            $given = (string) ($request->header('X-Api-Key') ?? $request->query('api_key'));
            $ok = collect([(string) config('vix.api_key'), $escrita])
                ->contains(fn ($expected) => $expected !== '' && hash_equals($expected, $given));
        }

        if (! $ok) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
