#!/usr/bin/env python3
import os
import sys
import json
import re
import urllib.request
import urllib.error
import urllib.parse
from datetime import datetime

# Path configs
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR = os.path.dirname(SCRIPT_DIR)
ENV_PATH = os.path.join(ROOT_DIR, ".env")
BACKLOG_PATH = os.path.join(ROOT_DIR, "REVIEW_BACKLOG.md")
COMPLETED_PATH = os.path.join(ROOT_DIR, "REVIEW_COMPLETED.md")

def load_env():
    env_vars = {}
    if os.path.exists(ENV_PATH):
        with open(ENV_PATH, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    env_vars[k.strip()] = v.strip().strip('"').strip("'")
    return env_vars

# Load settings
env = load_env()
GITHUB_TOKEN = env.get("GITHUB_TOKEN", "")
GITHUB_REPO = env.get("GITHUB_REPO", "AlfiMuhtadii/enterprise-SIEM")

def make_request(url, method="GET", data=None):
    if not GITHUB_TOKEN:
        print("Error: GITHUB_TOKEN is not defined in your .env file.")
        print("Please generate a GitHub Personal Access Token (classic) with 'repo' scope and add it to .env.")
        sys.exit(1)
        
    req = urllib.request.Request(url)
    req.method = method
    req.add_header("Authorization", f"token {GITHUB_TOKEN}")
    req.add_header("Accept", "application/vnd.github.v3+json")
    req.add_header("User-Agent", "XDR-Backlog-Sync-Agent")
    
    encoded_data = None
    if data:
        req.add_header("Content-Type", "application/json")
        encoded_data = json.dumps(data).encode("utf-8")
        
    try:
        with urllib.request.urlopen(req, data=encoded_data) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        print(f"HTTP Error: {e.code} - {e.reason}")
        try:
            err_body = e.read().decode("utf-8")
            print(f"Details: {err_body}")
        except Exception:
            pass
        sys.exit(1)
    except Exception as e:
        print(f"Network Connection Error: {e}")
        sys.exit(1)

def pull_backlog():
    print(f"Pulling issues from GitHub repository: {GITHUB_REPO}...")
    url = f"https://api.github.com/repos/{GITHUB_REPO}/issues?state=open"
    issues = make_request(url)
    
    # Filter out Pull Requests and Gemini Audit tasks (which are not for developer backlog)
    clean_issues = []
    for i in issues:
        if "pull_request" in i:
            continue
        title = i.get("title", "")
        if title.startswith("[AUDIT]") or "[AUDIT]" in title:
            continue
        labels = [l["name"] for l in i.get("labels", [])]
        if "agent:gemini-review" in labels:
            continue
        clean_issues.append(i)
    
    print(f"Found {len(clean_issues)} open developer tasks.")
    
    # Rebuild TASKS_BACKLOG.md
    markdown_lines = [
        "# Pending Hardening & Cleanup Tasks (Backlog)\n",
        "This file tracks all pending security hardening, refactoring, and documentation alignment tasks.",
        "It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.\n",
        "---\n"
    ]
    
    if not clean_issues:
        markdown_lines.append("## *No open tasks currently.* All issues are closed or completed!\n")
    else:
        # Group issues (we can parse label metadata if needed, here we list them neatly)
        for issue in clean_issues:
            num = issue["number"]
            title = issue["title"]
            body = issue.get("body", "No description provided.") or "No description provided."
            labels = ", ".join([l["name"] for l in issue.get("labels", [])])
            label_str = f" [Labels: {labels}]" if labels else ""
            
            markdown_lines.append(f"## Task #{num}: {title}{label_str}")
            markdown_lines.append(f"* **Target**: GitHub Issue [#{num}](https://github.com/{GITHUB_REPO}/issues/{num})")
            markdown_lines.append(f"* **Goal**: Grounded implementation based on issue definition.\n")
            markdown_lines.append("### Goal & Requirements:")
            
            # Format body content with nice indentation
            body_indented = "\n".join([f"> {line}" if line.strip() else "" for line in body.splitlines()])
            markdown_lines.append(body_indented)
            markdown_lines.append("\n---\n")
            
    with open(BACKLOG_PATH, "w", encoding="utf-8") as f:
        f.write("\n".join(markdown_lines))
        
    print(f"Successfully updated: {BACKLOG_PATH}")

def close_issue(issue_number, comment=None):
    # 1. Post comment if provided
    if comment:
        print(f"Posting comment to Issue #{issue_number}...")
        comment_url = f"https://api.github.com/repos/{GITHUB_REPO}/issues/{issue_number}/comments"
        make_request(comment_url, method="POST", data={"body": comment})
        
    # 2. Close issue
    print(f"Closing Issue #{issue_number} on GitHub...")
    close_url = f"https://api.github.com/repos/{GITHUB_REPO}/issues/{issue_number}"
    make_request(close_url, method="PATCH", data={"state": "closed"})
    
    # 3. Pull fresh backlog to sync local file
    pull_backlog()

def create_issue(title, description=None, labels=None):
    print(f"Creating new GitHub Issue: '{title}'...")
    url = f"https://api.github.com/repos/{GITHUB_REPO}/issues"
    data = {"title": title}
    if description:
        data["body"] = description
    if labels:
        data["labels"] = labels
        
    response = make_request(url, method="POST", data=data)
    print(f"Successfully created Issue #{response['number']}: {response['html_url']}")
    pull_backlog()

def main():
    if len(sys.argv) < 2:
        print("Usage:")
        print("  python scripts/sync_backlog.py --pull")
        print("  python scripts/sync_backlog.py --close <issue_number> [--comment 'triage notes']")
        print("  python scripts/sync_backlog.py --create '<title>' ['<description>'] ['label1,label2']")
        sys.exit(1)
        
    cmd = sys.argv[1]
    
    if cmd == "--pull":
        pull_backlog()
    elif cmd == "--close":
        if len(sys.argv) < 3:
            print("Error: Missing issue number.")
            sys.exit(1)
        num = int(sys.argv[2])
        comment = None
        if len(sys.argv) >= 5 and sys.argv[3] == "--comment":
            comment = sys.argv[4]
        close_issue(num, comment)
    elif cmd == "--create":
        if len(sys.argv) < 3:
            print("Error: Missing title.")
            sys.exit(1)
        title = sys.argv[2]
        desc = sys.argv[3] if len(sys.argv) >= 4 else None
        labels = [l.strip() for l in sys.argv[4].split(",")] if len(sys.argv) >= 5 else None
        create_issue(title, desc, labels)
    else:
        print(f"Unknown command: {cmd}")
        sys.exit(1)

if __name__ == "__main__":
    main()
