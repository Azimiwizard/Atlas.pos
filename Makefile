.PHONY: up migrate seed

up:
	@cd api && php artisan serve

migrate:
	@cd api && php artisan migrate

seed:
	@cd api && php artisan db:seed

.PHONY: storage-link

storage-link:
	@cd api && php artisan storage:link
