import unittest
from pathlib import Path

from scripts import xdr_ci_workflow_validate as validator


class CIWorkflowValidatorTests(unittest.TestCase):
    def test_pinned_and_local_actions_pass(self):
        checkout = validator.APPROVED_NODE24_REFS["actions/checkout"]
        text = f"steps:\n  - uses: actions/checkout@{checkout} # v7\n  - uses: ./local-action\n"

        errors, action_count, pinned_count = validator.validate_workflow(Path("ci.yml"), text)

        self.assertEqual([], errors)
        self.assertEqual(1, action_count)
        self.assertEqual(1, pinned_count)

    def test_mutable_tag_is_rejected(self):
        errors, action_count, pinned_count = validator.validate_workflow(
            Path("ci.yml"), "steps:\n  - uses: actions/checkout@v7\n"
        )

        self.assertEqual(1, action_count)
        self.assertEqual(0, pinned_count)
        self.assertTrue(any("40-character commit SHA" in error for error in errors))

    def test_unapproved_official_revision_is_rejected(self):
        stale_revision = "0" * 40
        errors, _, pinned_count = validator.validate_workflow(
            Path("ci.yml"), f"steps:\n  - uses: actions/setup-python@{stale_revision}\n"
        )

        self.assertEqual(1, pinned_count)
        self.assertTrue(any("approved Node 24 revision" in error for error in errors))

    def test_mutable_container_action_is_rejected(self):
        errors, action_count, pinned_count = validator.validate_workflow(
            Path("ci.yml"), "steps:\n  - uses: docker://alpine:latest\n"
        )

        self.assertEqual(1, action_count)
        self.assertEqual(0, pinned_count)
        self.assertTrue(any("sha256 digest" in error for error in errors))

    def test_repository_workflows_pass(self):
        paths = validator.workflow_paths()
        errors, action_count, pinned_count = validator.validate_paths(paths)

        self.assertGreaterEqual(len(paths), 4)
        self.assertGreater(action_count, 0)
        self.assertEqual(action_count, pinned_count)
        self.assertEqual([], errors)


if __name__ == "__main__":
    unittest.main()
