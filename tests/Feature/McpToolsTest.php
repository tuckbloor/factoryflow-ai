<?php

namespace Tests\Feature;

use App\Mcp\Servers\MyMcpServer;
use App\Mcp\Tools\ListFilesTool;
use App\Mcp\Tools\ReadFileTool;
use App\Mcp\Tools\SearchFilesTool;
use App\Mcp\Tools\SystemInfoTool;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class McpToolsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/mcp-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0755, true);
        config(['mcp-files.directory' => $this->root]);
        file_put_contents($this->root.'/example.txt', 'Harmless UTF-8 sample');
    }

    protected function tearDown(): void
    {
        // Only remove the unique fixture directory created by this test.
        if (isset($this->root) && str_starts_with($this->root, storage_path('framework/testing/mcp-'))) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_registration_and_successful_tools(): void
    {
        MyMcpServer::tools()->assertRegistered([ListFilesTool::class, ReadFileTool::class, SearchFilesTool::class, SystemInfoTool::class]);
        MyMcpServer::tool(SystemInfoTool::class)->assertOk()->assertSee(PHP_VERSION);
        MyMcpServer::tool(ListFilesTool::class)->assertOk()->assertSee('example.txt')->assertSee('size_bytes');
        MyMcpServer::tool(SearchFilesTool::class, ['query' => ' EXAMPLE '])->assertOk()->assertSee('example.txt');
        MyMcpServer::tool(SearchFilesTool::class, ['query' => 'absent'])->assertOk()->assertSee('"total_matches": 0');
        MyMcpServer::tool(ReadFileTool::class, ['filename' => 'example.txt'])->assertOk()->assertSee('Harmless UTF-8 sample');
    }

    public function test_invalid_arguments_and_paths(): void
    {
        foreach (['../.env', '..\\.env', '/etc/passwd', 'C:\\secret.txt', 'C:secret.txt', 'file.txt:stream', 'CON.txt', '.env', 'file.php', "a\0.txt", "bad\xff.txt", str_repeat('a', 100).'.txt', '', 1, []] as $name) {
            MyMcpServer::tool(ReadFileTool::class, ['filename' => $name])->assertHasErrors();
        }
        MyMcpServer::tool(ReadFileTool::class)->assertHasErrors();
        foreach (['', '   ', str_repeat('a', 101), "bad\0", 1, []] as $query) {
            MyMcpServer::tool(SearchFilesTool::class, ['query' => $query])->assertHasErrors();
        }
        MyMcpServer::tool(SearchFilesTool::class)->assertHasErrors();
        MyMcpServer::tool(ReadFileTool::class, ['filename' => 'absent.txt'])->assertHasErrors(['File not found or not allowed.']);
    }

    public function test_text_and_size_boundaries(): void
    {
        foreach (["binary\0text", "invalid\xff"] as $content) {
            file_put_contents($this->root.'/bad.txt', $content);
            MyMcpServer::tool(ReadFileTool::class, ['filename' => 'bad.txt'])->assertHasErrors(['UTF-8']);
        }
        file_put_contents($this->root.'/large.txt', str_repeat('a', 1048577));
        MyMcpServer::tool(ReadFileTool::class, ['filename' => 'large.txt'])->assertHasErrors(['1 MiB']);
        file_put_contents($this->root.'/large.txt', str_repeat('a', 1048576));
        MyMcpServer::tool(ReadFileTool::class, ['filename' => 'large.txt'])->assertOk();
        file_put_contents($this->root.'/empty.txt', '');
        MyMcpServer::tool(ReadFileTool::class, ['filename' => 'empty.txt'])->assertOk();
    }

    public function test_missing_directory_and_non_recursive_listing(): void
    {
        mkdir($this->root.'/nested.txt');
        file_put_contents($this->root.'/nested.txt/private.txt', 'fixture only');
        file_put_contents($this->root.'/unsupported.php', '<?php');
        MyMcpServer::tool(ListFilesTool::class)->assertOk()->assertDontSee(['private.txt', 'nested.txt', 'unsupported.php']);
        MyMcpServer::tool(ReadFileTool::class, ['filename' => 'nested.txt'])->assertHasErrors();
        config(['mcp-files.directory' => $this->root.'/missing']);
        foreach ([ListFilesTool::class, SearchFilesTool::class, ReadFileTool::class] as $tool) {
            MyMcpServer::tool($tool, ['filename' => 'example.txt', 'query' => 'example'])->assertHasErrors(['directory is unavailable']);
        }
    }

    public function test_directory_and_search_limits(): void
    {
        for ($i = 0; $i < 101; $i++) {
            file_put_contents($this->root.'/match-'.$i.'.txt', 'x');
        }
        MyMcpServer::tool(SearchFilesTool::class, ['query' => 'match-'])->assertOk()->assertSee('"total_matches": 101')->assertDontSee('match-99.txt');
        for ($i = 0; $i < 900; $i++) {
            file_put_contents($this->root.'/ignored-'.$i, '');
        }
        MyMcpServer::tool(ListFilesTool::class)->assertHasErrors(['1000 entry limit']);
        MyMcpServer::tool(SearchFilesTool::class, ['query' => 'match'])->assertHasErrors(['1000 entry limit']);
    }

    public function test_symlink_is_rejected(): void
    {
        $target = $this->root.'/example.txt';
        $link = $this->root.'/link.txt';
        if (! @symlink($target, $link)) {
            $this->markTestSkipped('OS does not permit symlink creation; Linux CI must verify this.');
        }
        try {
            MyMcpServer::tool(ReadFileTool::class, ['filename' => 'link.txt'])->assertHasErrors();
            MyMcpServer::tool(ListFilesTool::class)->assertOk()->assertDontSee('link.txt');
        } finally {
            unlink($link);
        }
    }

    public function test_http_endpoint_and_tool_discovery(): void
    {
        $this->get('/mcp')->assertStatus(405);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])
            ->assertOk()->assertJsonCount(4, 'result.tools');
    }
}
