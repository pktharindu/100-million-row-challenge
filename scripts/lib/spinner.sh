#!/bin/bash
# Spinner module for ralph.sh
# Animated spinner display with step tracking and multi-line preview
# Dependencies: constants.sh, timing.sh, terminal.sh

# Spinner characters
SPINNER='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
SPINNER_PID=""
CURRENT_STEP="Thinking"
PREVIEW_MAX_LINES=8

# Spinner function - runs in background, reads step and preview from temp files
# Shows: spinner + step on line 1, then up to PREVIEW_MAX_LINES of rolling output
# Usage: start_spinner; do_work; stop_spinner
start_spinner() {
  echo "Thinking" > "$STEP_FILE"
  : > "$PREVIEW_LINE_FILE"

  (
    local -a SPIN_CHARS=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
    local i=0
    local spin_len=${#SPIN_CHARS[@]}
    local term_width=$(tput cols 2>/dev/null || echo 80)
    local preview_max=$((term_width - 8))
    local prev_total_lines=0

    while true; do
      local step=$(cat "$STEP_FILE" 2>/dev/null || echo "Thinking")
      local char="${SPIN_CHARS[$i]}"

      # Move cursor up to overwrite previous frame
      if [ $prev_total_lines -gt 0 ]; then
        printf "\033[${prev_total_lines}A"
      fi

      # Line 1: spinner + step
      printf "\033[K  ${C}%s${R} %s\n" "$char" "$step"
      local lines_rendered=1

      # Read preview lines from file and render each
      if [ -s "$PREVIEW_LINE_FILE" ]; then
        while IFS= read -r pline || [ -n "$pline" ]; do
          [ -z "$pline" ] && continue
          # Truncate long lines
          if [ ${#pline} -gt $preview_max ]; then
            pline="${pline:0:$((preview_max - 3))}..."
          fi
          printf "\033[K    ${D}▸ %s${R}\n" "$pline"
          lines_rendered=$((lines_rendered + 1))
        done < "$PREVIEW_LINE_FILE"
      fi

      # Clear leftover lines from previous frame if we rendered fewer this time
      local j=$lines_rendered
      while [ $j -lt $prev_total_lines ]; do
        printf "\033[K\n"
        j=$((j + 1))
      done

      # If we cleared extra lines, move cursor back to end of current content
      if [ $j -gt $lines_rendered ]; then
        printf "\033[%dA" $((j - lines_rendered))
      fi

      prev_total_lines=$lines_rendered
      i=$(( (i + 1) % spin_len ))
      sleep 0.15
    done
  ) &
  SPINNER_PID=$!
}

# Stop the spinner and clear its display area
stop_spinner() {
  if [ -n "$SPINNER_PID" ] && kill -0 "$SPINNER_PID" 2>/dev/null; then
    kill "$SPINNER_PID" 2>/dev/null
    wait "$SPINNER_PID" 2>/dev/null || true

    # Count total lines to clear (1 spinner + preview lines)
    local line_count=1
    if [ -s "$PREVIEW_LINE_FILE" ]; then
      local pcount
      pcount=$(wc -l < "$PREVIEW_LINE_FILE" 2>/dev/null | tr -d ' ')
      line_count=$((line_count + pcount))
    fi

    # Move up and clear each line
    printf "\033[${line_count}A"
    for ((j=0; j<line_count; j++)); do
      printf "\033[K\n"
    done
    printf "\033[${line_count}A\r"
  fi
  SPINNER_PID=""
}

# Update spinner step based on output line and record step timing
update_spinner_step() {
  local line="$1"
  local detected=$(detect_step "$line")
  if [ -n "$detected" ]; then
    echo "$detected" > "$STEP_FILE"

    # Clean step name (remove trailing spaces) for timing tracking
    local clean_step=$(echo "$detected" | sed 's/ *$//')

    # Record timing if step changed
    if [ "$clean_step" != "$CURRENT_STEP_NAME" ]; then
      record_step_time "$clean_step"
    fi
  fi
}

# Update the rolling preview buffer shown under the spinner
# Maintains a file with the last PREVIEW_MAX_LINES lines
update_preview_line() {
  local line="$1"
  # Skip empty lines or lines that are just whitespace
  [ -z "$line" ] && return
  [[ "$line" =~ ^[[:space:]]*$ ]] && return
  # Sanitize: replace newlines/tabs with spaces, collapse multiple spaces
  line=$(echo "$line" | tr '\n\t\r' ' ' | sed 's/  */ /g; s/^ *//; s/ *$//')
  # Skip if sanitization resulted in empty string
  [ -z "$line" ] && return

  # Append line and keep only the last N lines
  echo "$line" >> "$PREVIEW_LINE_FILE"
  local tmp
  tmp=$(tail -n "$PREVIEW_MAX_LINES" "$PREVIEW_LINE_FILE")
  echo "$tmp" > "$PREVIEW_LINE_FILE"
}
