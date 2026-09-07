<img src="https://otgh-static-assets.s3.otgh.cloud/branding/logos/otgh_cloud_2024.png" alt="OTGH Cloud" width="200px" />

# Aurora GHARM (GitHub Actions Runner Maager)

A complete solution to building, provisioning and managing self-hosted GitHub Actions runner VM's within a Proxmox environment.

## Getting Started

```bash
docker run -d --name aurora-gha-manager \
  --restart=always \
  --network host \
  -v aurora-gha-manager-data:/data \
  -e APP_URL=https://runners.example.com \
  -e TRUSTED_PROXIES='*' \
  ghcr.io/otghcloud/aurora-gha-manager:latest
```

Open the address in a browser and the setup wizard will guide you through the rest of the setup and configuration.

> [!IMPORTANT]
> The `/data` volume holds the SQLite database **and** the generated `APP_KEY` that encrypts
> every stored Proxmox and GitHub credential. Lose that key and the secrets are unrecoverable.

## Documentation

Documentation is in the works and will available in due course.

## Contributing

We'd love to have your input and value all contributions, large or small.

Please review [CONTRIBUTING.md](CONTRIBUTING.md) for additional information and required conventions.

## License

This repository uses the MIT license.

Please review [LICENSE.md](LICENSE.md) for more details.

## Security

Please review [SECURITY.md](SECURITY.md) for more details.