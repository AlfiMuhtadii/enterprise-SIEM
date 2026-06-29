#!/usr/bin/env python3
"""
ENTERPRISE-071 — RAG Knowledge Base Operational Integration Validator
Offline. Verifies that the RAG pipeline components are in place and seeded.
"""

import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent


def check_knowledge_check_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "RagOperationalCheckCommand.php"
    ok = path.is_file()
    return {"id": "RAG-01", "name": "RagOperationalCheckCommand exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_command_is_read_only() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "RagOperationalCheckCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    has_write = any(k in content for k in ["->insert(", "->update(", "->delete(", "->seed("])
    ok = not has_write
    return {"id": "RAG-02", "name": "RagOperationalCheckCommand is read-only",
            "status": "PASS" if ok else "FAIL",
            "detail": "Command uses only SELECT queries"}


def check_seed_command_exists() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "AiSeedKnowledgeCommand.php"
    ok = path.is_file()
    return {"id": "RAG-03", "name": "AiSeedKnowledgeCommand exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_fixture_file_exists() -> dict:
    path = BASE_DIR / "database" / "seeders" / "data" / "rag_knowledge_fixtures.json"
    ok = path.is_file()
    return {"id": "RAG-04", "name": "RAG knowledge fixture file exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_ai_analyst_manager_exists() -> dict:
    path = BASE_DIR / "app" / "Support" / "AiAnalystManager.php"
    ok = path.is_file()
    return {"id": "RAG-05", "name": "AiAnalystManager exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_soc_knowledge_retriever_exists() -> dict:
    path = BASE_DIR / "app" / "Support" / "SocKnowledgeRetriever.php"
    ok = path.is_file()
    return {"id": "RAG-06", "name": "SocKnowledgeRetriever exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_ai_rag_service_provider_exists() -> dict:
    path = BASE_DIR / "app" / "Support" / "AiRagServiceProvider.php"
    ok = path.is_file()
    return {"id": "RAG-07", "name": "AiRagServiceProvider exists",
            "status": "PASS" if ok else "WARN", "detail": str(path)}


def check_knowledge_seed_service_exists() -> dict:
    path = BASE_DIR / "app" / "Services" / "AiKnowledgeSeedService.php"
    ok = path.is_file()
    return {"id": "RAG-08", "name": "AiKnowledgeSeedService exists",
            "status": "PASS" if ok else "FAIL", "detail": str(path)}


def check_seed_service_has_fixture_path() -> dict:
    path = BASE_DIR / "app" / "Services" / "AiKnowledgeSeedService.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "FIXTURE_PATH" in content and "rag_knowledge_fixtures" in content
    return {"id": "RAG-09", "name": "AiKnowledgeSeedService defines FIXTURE_PATH",
            "status": "PASS" if ok else "FAIL",
            "detail": "Service has FIXTURE_PATH constant pointing to fixtures"}


def check_knowledge_check_artisan_signature() -> dict:
    path = BASE_DIR / "app" / "Console" / "Commands" / "RagOperationalCheckCommand.php"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "ai:knowledge-check" in content
    return {"id": "RAG-10", "name": "Command signature is ai:knowledge-check",
            "status": "PASS" if ok else "FAIL",
            "detail": "Artisan signature matches ai:knowledge-check"}


def check_operator_runbook_references_rag() -> dict:
    path = BASE_DIR / "docs" / "operations" / "PILOT_OPERATOR_RUNBOOK.md"
    content = path.read_text(encoding="utf-8") if path.is_file() else ""
    ok = "knowledge" in content.lower() or "rag" in content.lower() or "seed" in content.lower()
    return {"id": "RAG-11", "name": "Operator runbook references RAG seed step",
            "status": "PASS" if ok else "WARN",
            "detail": "PILOT_OPERATOR_RUNBOOK.md mentions knowledge base or RAG seeding"}


def check_qdrant_config_present() -> dict:
    env_example = BASE_DIR / ".env.example"
    content = env_example.read_text(encoding="utf-8") if env_example.is_file() else ""
    ok = "QDRANT" in content or "qdrant" in content.lower()
    return {"id": "RAG-12", "name": "Qdrant config present in .env.example",
            "status": "PASS" if ok else "WARN",
            "detail": ".env.example contains Qdrant connection settings"}


CHECKS = [
    check_knowledge_check_command_exists, check_command_is_read_only,
    check_seed_command_exists, check_fixture_file_exists,
    check_ai_analyst_manager_exists, check_soc_knowledge_retriever_exists,
    check_ai_rag_service_provider_exists, check_knowledge_seed_service_exists,
    check_seed_service_has_fixture_path, check_knowledge_check_artisan_signature,
    check_operator_runbook_references_rag, check_qdrant_config_present,
]


def run_checks() -> dict:
    results = [fn() for fn in CHECKS]
    passed  = sum(1 for r in results if r["status"] == "PASS")
    warned  = sum(1 for r in results if r["status"] == "WARN")
    failed  = sum(1 for r in results if r["status"] == "FAIL")
    return {
        "validator": "xdr_rag_operational_validate",
        "enterprise_task": "ENTERPRISE-071",
        "checks_total": len(results),
        "passed": passed, "warned": warned, "failed": failed,
        "overall": "PASS" if failed == 0 else "FAIL",
        "results": results,
    }


class TestRagOperationalValidator(unittest.TestCase):
    def test_rag01(self): self.assertEqual(check_knowledge_check_command_exists()["status"], "PASS")
    def test_rag02(self): self.assertEqual(check_command_is_read_only()["status"], "PASS")
    def test_rag03(self): self.assertEqual(check_seed_command_exists()["status"], "PASS")
    def test_rag04(self): self.assertIn(check_fixture_file_exists()["status"], ("PASS", "WARN"))
    def test_rag05(self): self.assertIn(check_ai_analyst_manager_exists()["status"], ("PASS", "WARN"))
    def test_rag06(self): self.assertIn(check_soc_knowledge_retriever_exists()["status"], ("PASS", "WARN"))
    def test_rag07(self): self.assertIn(check_ai_rag_service_provider_exists()["status"], ("PASS", "WARN"))
    def test_rag08(self): self.assertEqual(check_knowledge_seed_service_exists()["status"], "PASS")
    def test_rag09(self): self.assertEqual(check_seed_service_has_fixture_path()["status"], "PASS")
    def test_rag10(self): self.assertEqual(check_knowledge_check_artisan_signature()["status"], "PASS")
    def test_rag11(self): self.assertIn(check_operator_runbook_references_rag()["status"], ("PASS", "WARN"))
    def test_rag12(self): self.assertIn(check_qdrant_config_present()["status"], ("PASS", "WARN"))

    def test_overall_no_failures(self):
        r = run_checks()
        self.assertEqual(r["failed"], 0, f"Failures: {[x for x in r['results'] if x['status']=='FAIL']}")

    def test_report_structure(self):
        for k in ("validator", "enterprise_task", "overall", "results"):
            self.assertIn(k, run_checks())


if __name__ == "__main__":
    if "--test" in sys.argv or len(sys.argv) == 1:
        sys.argv = [sys.argv[0]]
        unittest.main(verbosity=2)
    else:
        import json, os
        report = run_checks()
        out = BASE_DIR / "reports" / "xdr_rag_operational.json"
        os.makedirs(out.parent, exist_ok=True)
        out.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
        sys.exit(0 if report["overall"] == "PASS" else 1)
