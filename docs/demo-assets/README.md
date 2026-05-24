# Demo Assets

This directory contains demo assets for presentations, portfolio showcases, and thesis defense.

## Directory Structure

```
docs/demo-assets/
├── README.md                    (this file)
├── diagrams/                    (exported architecture diagrams)
│   ├── event_flow.png
│   ├── system_context.png
│   ├── replay_pipeline.png
│   ├── detection_lifecycle.png
│   ├── threat_hunting_flow.png
│   ├── governance_workflow.png
│   └── ha_topology.png
├── slides/                      (presentation slide decks)
└── exports/                     (JSON/CSV platform data exports)
```

## Generating Diagrams from PlantUML

PlantUML source files are in `docs/architecture/plantuml/`.

```bash
# Install PlantUML (requires Java)
# https://plantuml.com/download

# Generate all diagrams as PNG
java -jar plantuml.jar docs/architecture/plantuml/*.puml -o ../../demo-assets/diagrams/

# Or use the PlantUML online server for individual diagrams:
# https://www.plantuml.com/plantuml/uml/
```

Available PlantUML sources:
- `docs/architecture/plantuml/system_context.puml` — system context diagram
- `docs/architecture/plantuml/event_flow.puml` — event flow diagram
- `docs/architecture/plantuml/replay_pipeline.puml` — replay pipeline sequence
- `docs/architecture/plantuml/detection_lifecycle.puml` — detection rule lifecycle
- `docs/architecture/plantuml/threat_hunting_flow.puml` — threat hunting flow
- `docs/architecture/plantuml/governance_workflow.puml` — SOAR governance workflow
- `docs/architecture/plantuml/ha_topology.puml` — HA topology
- `docs/thesis/use-case-diagram.puml` — use case diagram

## Platform Data Exports

Export platform state for demo presentations via the Showcase Dashboard:

URL: http://localhost:8000/demo-platform/showcase

Available export types:
- `capability_matrix` — full capability matrix (JSON/Markdown)
- `architecture_summary` — architecture summary (JSON/Markdown)
- `detection_coverage` — detection rule coverage report
- `validation_summary` — validation results summary
- `thesis_readiness` — thesis defense readiness summary
- `portfolio_summary` — portfolio showcase summary
- `governance_summary` — governance subsystem summary

All exports: deterministic, advisory-only, not fabricated.
