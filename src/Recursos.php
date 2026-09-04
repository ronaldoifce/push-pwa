<?php

namespace campus\push;

/**
 * Acesso aos arquivos JavaScript do pacote.
 *
 * O `vendor/` não é servido pela web nos sistemas do campus, então cada sistema
 * publica estes arquivos por uma rota própria em vez de apontar um `<script
 * src>` para dentro de `vendor/`. Isso também evita copiar o JS para dentro de
 * cada projeto, que é justamente o que faria as versões divergirem.
 */
class Recursos
{
    const CLIENTE = 'push-campus.js';
    const SERVICE_WORKER = 'campus-sw.js';

    /**
     * @return array conteudo, tipo, etag — o suficiente para o sistema montar a
     *               resposta na sua própria versão de Slim.
     */
    public static function entregar($nome)
    {
        $permitidos = array(self::CLIENTE, self::SERVICE_WORKER);
        if (!in_array($nome, $permitidos, true)) {
            throw new \InvalidArgumentException('Recurso de push desconhecido.');
        }

        $caminho = self::caminho($nome);
        $conteudo = @file_get_contents($caminho);
        if ($conteudo === false) {
            throw new \RuntimeException('Recurso de push indisponível.');
        }

        return array(
            'conteudo' => $conteudo,
            'tipo' => 'application/javascript; charset=utf-8',
            // Muda quando o pacote é atualizado, o que permite ao sistema
            // devolver 304 sem reler o arquivo e sem servir versão velha.
            'etag' => '"' . substr(hash('sha256', $conteudo), 0, 32) . '"'
        );
    }

    public static function caminho($nome)
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $nome;
    }

    public static function versao()
    {
        $partes = array();
        foreach (array(self::CLIENTE, self::SERVICE_WORKER) as $nome) {
            $partes[] = (string)@filemtime(self::caminho($nome));
        }
        return substr(hash('sha256', implode('-', $partes)), 0, 12);
    }
}
