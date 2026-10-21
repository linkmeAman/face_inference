#!/bin/sh
# Existing evaluator rejects datasets inside its app root. Stage public copies
# externally; keep reports private and never install their thresholds as policy.
set -eu
umask 077
bundle_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
evaluation_dir=$(mktemp -d /tmp/face-public-evaluation.XXXXXXXX)
cp -R "$bundle_root/datasets/controlface10k-subset" "$evaluation_dir/data"
cd "$bundle_root"
node evaluation/public_dataset_inference.cjs "$evaluation_dir/data" "$evaluation_dir/inference.json"
python3 evaluation/public_dataset_evaluate.py "$evaluation_dir/inference.json" --output "$evaluation_dir/report.json"
printf 'Private synthetic evaluation artifacts: %s\n' "$evaluation_dir"
