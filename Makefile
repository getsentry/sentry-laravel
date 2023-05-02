.PHONY: develop
develop: vendor update-submodules setup-git

vendor: composer.lock
	composer install

composer.lock: composer.json
	composer update

.PHONY: update-submodules
update-submodules:
	git submodule init
	git submodule update

.PHONY: setup-git
setup-git:
	git config branch.autosetuprebase always

.PHONY: cs
cs:
	vendor/bin/php-cs-fixer fix --verbose --diff

.PHONY: cs-dry-run
cs-dry-run:
	vendor/bin/php-cs-fixer fix --verbose --diff --dry-run

.PHONY: test
test:
	vendor/bin/phpunit
