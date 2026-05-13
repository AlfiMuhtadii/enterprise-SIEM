FROM golang:1.22-alpine AS build
WORKDIR /src
COPY go.mod ./
COPY main.go ./
RUN go build -o /out/normalizer-worker .

FROM alpine:3.20
RUN adduser -D -H app
USER app
COPY --from=build /out/normalizer-worker /usr/local/bin/normalizer-worker
EXPOSE 8092
ENTRYPOINT ["/usr/local/bin/normalizer-worker"]
