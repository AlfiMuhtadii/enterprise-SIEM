FROM golang:1.22-alpine AS build
WORKDIR /src
COPY go.mod ./
COPY main.go ./
RUN go build -o /out/ingestion-gateway .

FROM alpine:3.20
RUN adduser -D -H app
USER app
COPY --from=build /out/ingestion-gateway /usr/local/bin/ingestion-gateway
EXPOSE 8091
ENTRYPOINT ["/usr/local/bin/ingestion-gateway"]
