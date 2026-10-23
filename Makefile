VERSION ?= dev
CODENAME ?= development

PACKAGE_NAME := glacierdb-php-$(VERSION).tar.gz
PACKAGE_DIR := dist-package

.PHONY: help version zip build test package clean distclean

help: ## Show available targets
	@awk 'BEGIN {FS = ":.*?## "}; /^[a-zA-Z0-9_.-]+:.*?## / {printf "  \033[32m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

version: ## Show package version
	@echo "glacierdb-php $(VERSION) ($(CODENAME))"

zip: ## Make glacierdb-php.zip source archive
	rm -f glacierdb-php.zip
	zip -r glacierdb-php.zip src composer.json composer.lock 2>/dev/null || zip -r glacierdb-php.zip src composer.json

build: ## Install production PHP dependencies when Composer is configured
	@if [ -f composer.json ]; then \
		composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; \
	fi

test: ## Run PHP tests when configured
	@if [ -x vendor/bin/phpunit ]; then \
		vendor/bin/phpunit; \
	elif [ -f composer.json ] && composer run --list 2>/dev/null | grep -q '^  test'; then \
		composer test; \
	else \
		echo "No PHP test target configured"; \
	fi

package: build ## Create production PHP package
	rm -rf $(PACKAGE_DIR)
	mkdir -p $(PACKAGE_DIR)
	@if [ -d src ]; then cp -a src $(PACKAGE_DIR)/; fi
	@if [ -d vendor ]; then cp -a vendor $(PACKAGE_DIR)/; fi
	@if [ -f composer.json ]; then cp composer.json $(PACKAGE_DIR)/; fi
	@if [ -f composer.lock ]; then cp composer.lock $(PACKAGE_DIR)/; fi
	printf 'VERSION=%s\nCODENAME=%s\n' '$(VERSION)' '$(CODENAME)' > $(PACKAGE_DIR)/VERSION
	tar czf $(PACKAGE_NAME) -C $(PACKAGE_DIR) .
	@echo "Created $(PACKAGE_NAME)"

clean: ## Remove packaging files
	rm -rf $(PACKAGE_DIR) $(PACKAGE_NAME) glacierdb-php.zip

distclean: clean ## Remove installed Composer dependencies
	rm -rf vendor
