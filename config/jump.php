<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Docs source
    |---------------------------------------------------------------------------
    |
    | The MCP navigation API the Docs tab reads (full page content inline).
    | Point JUMP_DOCS_URL at a local docs site (e.g. Herd's nativephp.test) to
    | preview unpublished docs; leave unset for production.
    |
    */

    'docs_url' => env('JUMP_DOCS_URL', 'https://nativephp.com/api/mcp/navigation/mobile/4'),

];
