# ONTOGENY MCP

First-party Model Context Protocol interface for ONTOGENY. Discover publicly documented systems, retrieve organizational information and initiate direct correspondence through compatible MCP clients.

**Version 0.2.1 · GPL-2.0-or-later**

## Connection

```text
https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp
```

JSON-only Streamable HTTP. No prior registration or API credential required.

Example VS Code configuration (`.vscode/mcp.json`):

```json
{
  "servers": {
    "ontogeny": {
      "type": "http",
      "url": "https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp"
    }
  }
}
```

## Capabilities

| Tool | Function |
| --- | --- |
| `get_organization` | Retrieve public organizational information. |
| `list_systems` | Discover publicly documented systems. |
| `get_system` | Retrieve the StrataDOME dossier. |
| `get_contact_channels` | Discover correspondence channels. |
| `prepare_correspondence` | Prepare a message for review. |
| `send_correspondence` | Submit approved correspondence. |

## Development

The reference implementation runs on WordPress and PHP 8.1 or later. See the [integration guide](docs/INTEGRATION.md) for protocol details, installation and verification. Deployments supply their own private signing key and recipient configuration.

## License

[GPL-2.0-or-later](LICENSE).

[ONTOGENY](https://ontogenyai.com/) · [MCP documentation](https://ontogenyai.com/mcp/) · [Security](SECURITY.md) · [Changelog](CHANGELOG.md)
