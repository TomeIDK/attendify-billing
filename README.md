# Attendify Billing
Microservice integrating FOSSBilling for an integration project.  

## Features & Content:
- RabbitMQ consumer with CRUD for users, companies, events, payments, invoices
- RabbitMQ producer with CRUD for users and invoices
- Dockerfiles for consumer and producer
- Docker compose files for multiple environments
- Heartbeats for producer, consumer, fossbilling and mysql containers
- Custom mysql database scripts
- Bash scripts to automate executing custom sql scripts
