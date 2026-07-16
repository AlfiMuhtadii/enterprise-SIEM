"""Native Kafka transport helpers backed by librdkafka."""

from __future__ import annotations

import json
import time
from dataclasses import dataclass
from typing import Any, Dict, List

try:
    from confluent_kafka import Consumer, KafkaError, Producer
except ImportError:  # Allows REST-only development and unit-test environments.
    Consumer = None
    KafkaError = None
    Producer = None


def _require_dependency() -> None:
    if Consumer is None or Producer is None:
        raise RuntimeError(
            "XDR_KAFKA_TRANSPORT=native requires the confluent-kafka package"
        )


@dataclass(frozen=True)
class NativeBatch:
    records: List[Dict[str, Any]]


class NativeJsonConsumer:
    def __init__(self, brokers: str, group_id: str, topic: str, offset_reset: str) -> None:
        _require_dependency()
        self._consumer = Consumer({
            "bootstrap.servers": brokers,
            "group.id": group_id,
            "auto.offset.reset": offset_reset,
            "enable.auto.commit": False,
        })
        self._consumer.subscribe([topic])

    def poll_batch(self, timeout: float = 1.0, max_records: int = 500) -> NativeBatch:
        records: List[Dict[str, Any]] = []
        for index in range(max_records):
            message = self._consumer.poll(timeout if index == 0 else 0)
            if message is None:
                break
            error = message.error()
            if error:
                if KafkaError is not None and error.code() == KafkaError._PARTITION_EOF:
                    continue
                raise RuntimeError(f"Kafka consumer error: {error}")
            decode_error = None
            try:
                value = json.loads(message.value().decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError) as exc:
                value = {}
                decode_error = str(exc)
            record = {
                "topic": message.topic(),
                "partition": message.partition(),
                "offset": message.offset(),
                "value": value,
            }
            if decode_error:
                record["decode_error"] = decode_error
            records.append(record)
        return NativeBatch(records=records)

    def commit(self) -> None:
        self._consumer.commit(asynchronous=False)

    def close(self) -> None:
        self._consumer.close()


class NativeJsonProducer:
    def __init__(self, brokers: str) -> None:
        _require_dependency()
        self._producer = Producer({"bootstrap.servers": brokers})

    def produce(self, topic: str, events: List[Dict[str, Any]]) -> int:
        errors: List[str] = []

        def delivered(error: Any, _message: Any) -> None:
            if error is not None:
                errors.append(str(error))

        for event in events:
            payload = json.dumps(event, separators=(",", ":")).encode("utf-8")
            deadline = time.monotonic() + 10
            while True:
                try:
                    self._producer.produce(topic, value=payload, on_delivery=delivered)
                    break
                except BufferError:
                    if time.monotonic() >= deadline:
                        raise RuntimeError("Kafka producer queue remained full for 10 seconds")
                    self._producer.poll(0.1)
        remaining = self._producer.flush(10)
        if remaining or errors:
            detail = "; ".join(errors) if errors else f"{remaining} message(s) undelivered"
            raise RuntimeError(f"Kafka produce failed: {detail}")
        return len(events)
