<?php

namespace App\Infrastructure\OpenAPI;

use App\Application\Http\Response;

final class OpenApiController
{
    public function docsYaml(): Response
    {
        $path = dirname(__DIR__, 3) . '/docs/openapi.yaml';
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        return new Response(200, ['Content-Type' => 'application/yaml; charset=utf-8'], $content);
    }

    public function docsUi(): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: '/docs',
      dom_id: '#swagger-ui'
    });
  </script>
</body>
</html>
HTML;

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
