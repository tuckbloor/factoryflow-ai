<?php

namespace App\Mcp\Tools;

use App\Mcp\FileSandbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Reads a UTF-8 text file from the local sandbox. Read-only.')]
class ReadFileTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['filename' => $schema->string()->description('Simple supported filename, at most 100 ASCII characters.')->required()];
    }

    public function handle(Request $request): Response
    {
        $filename = $request->get('filename');
        if (! FileSandbox::validFilename($filename)) {
            return Response::error('Invalid or unsupported filename.');
        }
        try {
            return Response::text(app(FileSandbox::class)->read($filename));
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }
    }
}
