<?php

namespace App\Mcp\Tools;

use App\Mcp\FileSandbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Searches supported filenames in the local sandbox. Read-only.')]
class SearchFilesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('Filename substring, 1 to 100 UTF-8 characters after trimming.')->required()];
    }

    public function handle(Request $request): Response
    {
        $query = $request->get('query');
        if (! is_string($query) || ! mb_check_encoding($query, 'UTF-8') || strlen($query) > 400 || preg_match('/[\x00-\x1F\x7F]/', $query)) {
            return Response::error('Invalid search query.');
        }
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100) {
            return Response::error('Search must contain 1 to 100 characters.');
        }
        try {
            $matches = [];
            foreach (app(FileSandbox::class)->files() as $file) {
                if (mb_stripos($file['name'], $query) !== false) {
                    $matches[] = ['filename' => $file['name'], 'size_bytes' => $file['size_bytes']];
                }
            }

            return Response::text(json_encode(['query' => $query, 'total_matches' => count($matches), 'files' => array_slice($matches, 0, 100)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }
    }
}
