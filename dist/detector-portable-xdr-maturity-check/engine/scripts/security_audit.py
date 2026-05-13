#!/usr/bin/env python3
from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any, Dict, Optional


def insert_audit(
    conn: Any,
    action: str,
    target_type: str,
    actor: str = "system",
    target_id: Optional[str] = None,
    before_state: Optional[Dict[str, Any]] = None,
    after_state: Optional[Dict[str, Any]] = None,
    meta: Optional[Dict[str, Any]] = None,
) -> None:
    sql = """
    INSERT INTO security_audit_trails (
      occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at
    ) VALUES (
      %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, %s::jsonb, now(), now()
    )
    """
    now = datetime.now(timezone.utc).isoformat()
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                now,
                actor,
                action,
                target_type,
                target_id,
                json.dumps(before_state or {}, separators=(",", ":")),
                json.dumps(after_state or {}, separators=(",", ":")),
                json.dumps(meta or {}, separators=(",", ":")),
            ),
        )
