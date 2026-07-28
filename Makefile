BINARY  := asylumd
PKG     := github.com/philf90/asylum
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
COMMIT  := $(shell git rev-parse HEAD 2>/dev/null || echo unbekannt)
DATE    := $(shell date -u +%Y-%m-%dT%H:%M:%SZ)

LDFLAGS := -s -w \
	-X $(PKG)/internal/version.Version=$(VERSION) \
	-X $(PKG)/internal/version.Commit=$(COMMIT) \
	-X $(PKG)/internal/version.Date=$(DATE)

.PHONY: help build test lint fmt vet run dist clean check editor

help: ## Diese Übersicht
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

build: ## Binary nach bin/ bauen
	@mkdir -p bin
	CGO_ENABLED=0 go build -trimpath -ldflags "$(LDFLAGS)" -o bin/$(BINARY) ./cmd/$(BINARY)
	@ls -lh bin/$(BINARY)

test: ## Tests mit Race-Detector
	go test -race ./...

vet: ## go vet
	go vet ./...

fmt: ## Quellen formatieren
	gofmt -w .

lint: ## golangci-lint (falls installiert)
	@command -v golangci-lint >/dev/null 2>&1 \
		&& golangci-lint run \
		|| echo "golangci-lint nicht installiert — übersprungen"

check: fmt vet test ## Formatieren, prüfen, testen

run: build ## Lokal starten (Port 8443, Daten unter ./.local)
	@mkdir -p .local/etc/tls .local/var
	ASYLUM_CONFIG=.local/etc/config.yaml \
	ASYLUM_TLS_CERT=$(PWD)/.local/etc/tls/server.crt \
	ASYLUM_TLS_KEY=$(PWD)/.local/etc/tls/server.key \
	ASYLUM_DATA_DIR=$(PWD)/.local/var \
	ASYLUM_BIND=127.0.0.1 \
	ASYLUM_LOG_LEVEL=debug \
	./bin/$(BINARY) serve

editor: ## Editor-Bundle (CodeMirror) neu bauen
	@command -v npm >/dev/null 2>&1 || { echo "npm nicht installiert — der eingecheckte Bundle bleibt, wie er ist"; exit 0; }
	cd packaging/editor && npm ci --no-audit --no-fund && node build.mjs

dist: ## Release-Artefakte lokal bauen (ohne Veröffentlichung)
	@command -v goreleaser >/dev/null 2>&1 \
		&& goreleaser release --snapshot --clean \
		|| echo "goreleaser nicht installiert"

clean: ## Build-Artefakte entfernen
	rm -rf bin dist .local
