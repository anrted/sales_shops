#!/bin/bash
set -e

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Starting Discount Hub installation...${NC}\n"

# 1. Check Docker
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed.${NC}"
    echo "Please install Docker first: https://docs.docker.com/engine/install/ubuntu/"
    echo "Quick install for Ubuntu/Debian:"
    echo "curl -fsSL https://get.docker.com -o get-docker.sh && sudo sh get-docker.sh"
    exit 1
fi

# 2. Check Docker Compose
DOCKER_COMPOSE_CMD=""
if docker compose version &> /dev/null; then
    DOCKER_COMPOSE_CMD="docker compose"
elif docker-compose version &> /dev/null; then
    DOCKER_COMPOSE_CMD="docker-compose"
else
    echo -e "${RED}Error: Docker Compose is not installed.${NC}"
    echo "Please install Docker Compose plugin."
    exit 1
fi

# 3. Check Node.js and NPM
if ! command -v node &> /dev/null; then
    echo -e "${RED}Error: Node.js is not installed.${NC}"
    echo "Node.js is required for the Lenta session refresh tool."
    echo "Quick install for Ubuntu/Debian (Node 20):"
    echo "curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt-get install -y nodejs"
    exit 1
fi

if ! command -v npm &> /dev/null; then
    echo -e "${RED}Error: npm is not installed.${NC}"
    exit 1
fi

echo -e "${GREEN}All required system dependencies (Docker, Node.js) are present.${NC}\n"

# 4. Setup environment files
echo "Setting up environment files..."
if [ ! -f backend/.env ]; then
    cp backend/.env.example backend/.env
    echo -e "${YELLOW}Created backend/.env. You may need to edit it later to configure external APIs or change default passwords.${NC}"
else
    echo "backend/.env already exists."
fi

if [ ! -f frontend/.env ]; then
    cp frontend/.env.example frontend/.env
    echo -e "${YELLOW}Created frontend/.env. You may need to edit it later.${NC}"
else
    echo "frontend/.env already exists."
fi

# 5. Setup Lenta Session Refresh Tool
echo -e "\n${GREEN}Setting up Lenta session refresh tool...${NC}"
cd tools/lenta-session-refresh
npm install
echo "Installing Playwright system dependencies (this may ask for sudo password)..."
npx playwright install-deps chromium
echo "Installing Playwright Chromium browser..."
npx playwright install chromium
cd ../..

# 6. Start Docker Containers
echo -e "\n${GREEN}Starting Docker containers...${NC}"
$DOCKER_COMPOSE_CMD up -d --build

# 7. Initialize Laravel
echo -e "\n${GREEN}Waiting for containers to initialize (10 seconds)...${NC}"
sleep 10

echo -e "\n${GREEN}Initializing Laravel...${NC}"
$DOCKER_COMPOSE_CMD exec -T backend php artisan key:generate
$DOCKER_COMPOSE_CMD exec -T backend php artisan migrate --seed

echo -e "\n${GREEN}==========================================${NC}"
echo -e "${GREEN}Discount Hub has been successfully deployed!${NC}"
echo -e "${GREEN}==========================================${NC}"
echo -e "Frontend: ${YELLOW}http://localhost:3000${NC}"
echo -e "API:      ${YELLOW}http://localhost:8080/api${NC}"
echo -e "\n${YELLOW}Important post-installation steps:${NC}"
echo "1. Configure your domain and Nginx/Caddy proxy to point to localhost:3000 (frontend) and localhost:8080 (backend API)."
echo "2. Set up the nightly cron job for the Lenta session refresh."
echo "   Example (run 'crontab -e'):"
echo "   15 3 * * * cd $(pwd)/backend && /usr/bin/php artisan lenta:refresh-session >> $(pwd)/backend/storage/logs/lenta-session-refresh.log 2>&1"
