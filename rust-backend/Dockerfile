# Multi-stage build for Jinish Potro Rust Backend
FROM rust:1.80-bookworm AS builder

WORKDIR /usr/src/app

# Copy Cargo workspace configuration
COPY rust-backend/Cargo.toml rust-backend/Cargo.lock* ./
COPY rust-backend/crates ./crates

# Build release binary for web
RUN cargo build --release --bin web

# Minimal runtime image
FROM debian:bookworm-slim AS runner

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy binary from builder
COPY --from=builder /usr/src/app/target/release/web /usr/local/bin/web

# Copy templates and static assets
COPY rust-backend/templates /app/templates
COPY assets /app/assets

# Create uploads directory
RUN mkdir -p /app/assets/uploads

ENV PORT=80 \
    HOST=0.0.0.0 \
    TEMPLATES_DIR=/app/templates \
    ASSETS_DIR=/app/assets \
    UPLOADS_DIR=/app/assets/uploads \
    RUST_LOG=info

EXPOSE 80 8082

CMD ["/usr/local/bin/web"]
