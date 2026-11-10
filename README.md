# Meal Prepper

Simple PHP-based decision-making automation tool.

## Running with Docker

### Option 1: Docker Compose (Recommended)

Start the application:
```bash
docker compose up -d
```
Then visit [http://localhost:8080](http://localhost:8080) in your browser.

Stop the application:
```bash
docker compose down
```

### Option 2: Docker CLI

Build the image:
```bash
docker build -t meal-prepper:latest .
```

Run the container:
```bash
docker run -d --name meal-prepper -p 8080:80 meal-prepper:latest
```
Then open [http://localhost:8080](http://localhost:8080).
