FROM golang:1.26-alpine@sha256:3ad57304ad93bbec8548a0437ad9e06a455660655d9af011d58b993f6f615648 AS build
WORKDIR /src
COPY go.mod ./
COPY main.go ./
RUN go build -o /out/normalizer-worker .

FROM alpine:3.22@sha256:14358309a308569c32bdc37e2e0e9694be33a9d99e68afb0f5ff33cc1f695dce
RUN adduser -D -H app
USER app
COPY --from=build /out/normalizer-worker /usr/local/bin/normalizer-worker
EXPOSE 8092
ENTRYPOINT ["/usr/local/bin/normalizer-worker"]
