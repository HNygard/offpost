FROM node:18-alpine

WORKDIR /app

COPY package*.json ./
RUN if [ "$NODE_ENV" = "development" ]; then \
      npm install; \
    else \
      npm install --omit=dev; \
    fi

COPY . .

EXPOSE 3000

CMD if [ "$NODE_ENV" = "development" ]; then \
      npm run dev; \
    else \
      npm start; \
    fi
