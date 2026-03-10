FROM node:20-alpine

WORKDIR /app

# Копируем и устанавливаем зависимости отдельным слоем (кеширование)
COPY package.json .
RUN npm install --production --no-audit --no-fund

# Копируем исходники
COPY server.js .
COPY public/ public/

# Директория данных (будет смонтирована как volume)
RUN mkdir -p data

EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=5s --retries=3 \
  CMD wget -qO- http://localhost:3000/api/status || exit 1

CMD ["node", "server.js"]
