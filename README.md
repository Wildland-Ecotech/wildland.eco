# Wildland Ecotech

**501(c)(3) nonprofit developing open-source technology for ecological restoration.**

We restore landscapes damaged by wildfire and pollution, working at the intersection of ecology and technology to bridge the gap between landowners and a new generation eager to protect the planet.

**Website:** [wildland.eco](https://wildland.eco)
**Instagram:** [@wildland.ecotech](https://www.instagram.com/wildland.ecotech)
**LinkedIn:** [Wildland Ecotech](https://www.linkedin.com/company/wildland-ecotech/)
**EIN:** 33-3738514

## About This Repository

This is the open-source website for Wildland Ecotech, currently built as a static site using SvelteKit and served via nginx in Docker. The site serves as the public-facing home for our nonprofit, featuring information about our restoration programs, project areas, and a donation platform.

## Quick Start

**Development (with hot reload):**

```bash
cd ui && npm install && npm run dev
```

**Docker (production build):**

```bash
docker compose -f docker-compose.dev.yml up --build
```

The site will be available at `http://localhost:31337`.

## Documentation

See the [docs/](docs/) directory for detailed documentation:

- **[Organization](docs/ORGANIZATION.md)** — Mission, history, board structure, legal status
- **[Technical](docs/TECHNICAL.md)** — Website architecture, deployment, components
- **[Restoration](docs/RESTORATION.md)** — Project areas, programs, impact metrics
- **[Content](docs/CONTENT.md)** — Photo and video index for restoration media
- **[Roadmap](docs/ROADMAP.md)** — Technical and organizational plans

## Contributing

All of our projects are open source. Contributions, issues, and ideas are welcome. Please see our documentation for context on the project before contributing.

## License

See [LICENSE](LICENSE) for details.