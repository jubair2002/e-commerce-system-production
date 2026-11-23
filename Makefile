# ==============================================================================
# Jinish Potro Parity Testing & Migration Makefile
# ==============================================================================

SHELL := /bin/bash
NODE_PATH := /home/jubair/.nvm/versions/node/v26.13.1/bin:$$HOME/.cargo/bin:$$PATH

.PHONY: all parity golden test stack-up stack-down stack-reset lint

all: test parity

# Run Parity Testing Suite (Supports ROUTE=<name>, UNIT=<unit>, ALL=1)
parity:
	@export PATH="$(NODE_PATH)" && \
	cd parity-tests && \
	ROUTE="$(ROUTE)" UNIT="$(UNIT)" ALL="$(ALL)" node runner/cli.js

# Regenerate deterministic Golden Test Vectors
golden:
	@echo "Generating golden test vectors from native PHP implementation..."
	php parity-tests/golden/generate_golden_vectors.php

# Run all test suites: Rust workspace tests + PHP regression suites
test:
	@export PATH="$(NODE_PATH)" && \
	echo "=== Running Rust Workspace Tests ===" && \
	(cd rust-backend && cargo test --workspace) && \
	echo "=== Running Rust Clippy Lint ===" && \
	(cd rust-backend && cargo clippy --workspace -- -D warnings) && \
	echo "=== Running PHP Storage Suite ===" && \
	php tests/test_storage_suite.php && \
	echo "=== Running PHP RBAC Suite ===" && \
	php tests/test_rbac_suite.php && \
	echo "=== Running PHP Product Import Suite ===" && \
	php tests/test_product_import_suite.php

# Start the Docker Parity Stack
stack-up:
	docker compose -f docker-compose.parity.yml up -d --build

# Stop the Docker Parity Stack
stack-down:
	docker compose -f docker-compose.parity.yml down

# Completely reset the parity database volume and re-run all initialization migrations
stack-reset:
	docker compose -f docker-compose.parity.yml down -v
	docker compose -f docker-compose.parity.yml up -d --build
