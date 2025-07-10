# PayGate Laravel - Docker Commands

# Construir y levantar contenedores
up:
	docker-compose up -d

# Construir contenedores
build:
	docker-compose build

# Parar contenedores
down:
	docker-compose down

# Ver logs
logs:
	docker-compose logs -f

# Entrar al contenedor de la aplicación
shell:
	docker-compose exec app bash

# Ejecutar comandos Artisan
artisan:
	docker-compose exec app php artisan $(cmd)

# Instalar dependencias
install:
	docker-compose exec app composer install
	docker-compose exec app npm install

# Ejecutar migraciones
migrate:
	docker-compose exec app php artisan migrate

# Ejecutar migraciones con seeders
migrate-seed:
	docker-compose exec app php artisan migrate --seed

# Limpiar cache
cache-clear:
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan view:clear

# Generar clave de aplicación
key:
	docker-compose exec app php artisan key:generate

# Ejecutar tests
test:
	docker-compose exec app php artisan test

# Permisos de almacenamiento
permissions:
	docker-compose exec app chown -R www:www /var/www
	docker-compose exec app chmod -R 755 /var/www/storage
	docker-compose exec app chmod -R 755 /var/www/bootstrap/cache

# Backup de base de datos
backup:
	docker-compose exec mysql mysqldump -u root -proot_password paygate_laravel > backup_$(shell date +%Y%m%d_%H%M%S).sql

# Restaurar backup
restore:
	docker-compose exec -i mysql mysql -u root -proot_password paygate_laravel < $(file)

# Reiniciar todo
restart: down up

# Setup inicial completo
setup: build up install key migrate permissions
	@echo "Setup completado. Accede a http://localhost:8000"

# Ayuda
help:
	@echo "Comandos disponibles:"
	@echo "  make up          - Levantar contenedores"
	@echo "  make build       - Construir contenedores"
	@echo "  make down        - Parar contenedores"
	@echo "  make shell       - Entrar al contenedor"
	@echo "  make install     - Instalar dependencias"
	@echo "  make migrate     - Ejecutar migraciones"
	@echo "  make setup       - Setup inicial completo"