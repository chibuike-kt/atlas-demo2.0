<?php

namespace App\Services;

/**
 * RuleParserService
 *
 * Parses plain English rule text into structured rule config.
 * Designed to be swapped for Claude API with zero other changes.
 *
 * Input:  "every day convert 2000 to usdt on bep20 and send to binance wallet"
 * Output: structured array ready to save as a Rule
 */
class RuleParserService
{
  // ── Trigger patterns ─────────────────────────────────────────────────────

  private array $frequencyPatterns = [
    'hourly'  => ['every hour', 'hourly', 'each hour', 'every 1 hour'],
    'daily'   => ['every day', 'daily', 'each day', 'once a day', 'every morning', 'every night', 'every evening'],
    'weekly'  => ['every week', 'weekly', 'each week', 'once a week'],
    'monthly' => ['every month', 'monthly', 'each month', 'once a month'],
    'manual'  => ['manually', 'manual', 'on demand', 'when i say'],
    'deposit' => ['when salary', 'on salary', 'when i get paid', 'when deposit', 'on deposit', 'when money comes in', 'when i receive'],
    'balance' => ['when balance', 'when my balance', 'if balance', 'if my balance'],
  ];

  private array $dayNames = [
    'monday' => 1,
    'tuesday' => 2,
    'wednesday' => 3,
    'thursday' => 4,
    'friday' => 5,
    'saturday' => 6,
    'sunday' => 7,
    'mon' => 1,
    'tue' => 2,
    'wed' => 3,
    'thu' => 4,
    'fri' => 5,
    'sat' => 6,
    'sun' => 7,
  ];

  // ── Action patterns ───────────────────────────────────────────────────────

  private array $actionPatterns = [
    'convert_crypto' => [
      'convert',
      'swap',
      'buy usdt',
      'buy crypto',
      'buy btc',
      'exchange to',
      'change to usdt',
      'move to crypto',
      'into usdt',
      'to usdt',
      'to btc',
      'to eth',
      'to bnb',
    ],
    'save_piggyvest' => [
      'save to piggyvest',
      'piggyvest',
      'piggybank',
      'piggy vest',
      'save to piggy',
      'put in piggyvest',
    ],
    'save_cowrywise' => [
      'cowrywise',
      'cowry wise',
      'save to cowrywise',
      'invest in cowrywise',
      'put in cowrywise',
    ],
    'send_bank'      => [
      'send to',
      'transfer to',
      'pay',
      'give',
      'send',
    ],
    'pay_bill'       => [
      'pay dstv',
      'pay gotv',
      'pay startimes',
      'pay electricity',
      'pay nepa',
      'recharge',
      'buy airtime',
      'pay bill',
      'pay mtn',
      'pay airtel',
      'pay glo',
    ],
  ];

  private array $networkPatterns = [
    'bep20'   => ['bep20', 'bep-20', 'bnb', 'binance smart chain', 'bsc', 'binance chain'],
    'trc20'   => ['trc20', 'trc-20', 'tron', 'trx'],
    'erc20'   => ['erc20', 'erc-20', 'ethereum', 'eth chain'],
    'polygon' => ['polygon', 'matic', 'pol'],
    'solana'  => ['solana', 'sol'],
    'arbitrum' => ['arbitrum', 'arb'],
    'base'    => ['base', 'base chain'],
  ];

  private array $billPatterns = [
    'dstv'     => ['dstv'],
    'gotv'     => ['gotv', 'go tv'],
    'startimes' => ['startimes', 'star times'],
    'ekedc'    => ['ekedc', 'eko electricity', 'eko electric'],
    'ikedc'    => ['ikedc', 'ikeja electric', 'ikeja electricity'],
    'aedc'     => ['aedc', 'abuja electricity', 'abuja electric'],
    'mtn'      => ['mtn airtime', 'mtn recharge', 'mtn data'],
    'airtel'   => ['airtel airtime', 'airtel recharge'],
    'glo'      => ['glo airtime', 'glo recharge'],
    '9mobile'  => ['9mobile', 'etisalat'],
  ];

  private array $cryptoTokens = [
    'USDT' => ['usdt', 'tether'],
    'BTC'  => ['btc', 'bitcoin'],
    'ETH'  => ['eth', 'ethereum'],
    'BNB'  => ['bnb', 'binance coin'],
    'SOL'  => ['sol', 'solana'],
    'USDC' => ['usdc', 'usd coin'],
  ];

  // ── Main parse method ─────────────────────────────────────────────────────

  public function parse(string $ruleText, array $contacts = [], array $wallets = []): array
  {
    $text  = strtolower(trim($ruleText));
    $original = $ruleText;

    $trigger = $this->parseTrigger($text);
    $actions = $this->parseActions($text, $contacts, $wallets);
    $name    = $this->generateName($ruleText, $trigger, $actions);

    $confidence = $this->calculateConfidence($trigger, $actions, $text);

    return [
      'understood'   => true,
      'confidence'   => $confidence,
      'name'         => $name,
      'rule_text'    => $original,
      'trigger'      => $trigger,
      'actions'      => $actions,
      'summary'      => $this->generateSummary($trigger, $actions),
      'warnings'     => $this->generateWarnings($trigger, $actions, $text),
    ];
  }

  // ── Trigger parsing ───────────────────────────────────────────────────────

  private function parseTrigger(string $text): array
  {
    $frequency = 'manual';
    $config    = [];

    // Check frequency patterns
    foreach ($this->frequencyPatterns as $freq => $patterns) {
      foreach ($patterns as $pattern) {
        if (str_contains($text, $pattern)) {
          $frequency = $freq;
          break 2;
        }
      }
    }

    // Parse specific day for weekly
    if ($frequency === 'weekly') {
      foreach ($this->dayNames as $name => $num) {
        if (str_contains($text, $name)) {
          $config['day_of_week'] = $num;
          $config['day_name']    = ucfirst($name);
          break;
        }
      }
    }

    // Parse day of month for monthly / "on the Xth"
    if ($frequency === 'monthly' || preg_match('/on the (\d{1,2})(st|nd|rd|th)?/i', $text, $m)) {
      if (preg_match('/on the (\d{1,2})(st|nd|rd|th)?/i', $text, $m)) {
        $frequency = 'monthly';
        $config['day'] = (int) $m[1];
      } elseif (preg_match('/(\d{1,2})(st|nd|rd|th)?\s+of\s+(each|every|the)\s+month/i', $text, $m)) {
        $config['day'] = (int) $m[1];
      } else {
        $config['day'] = 1;
      }
    }

    // Parse time
    if (preg_match('/at\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $text, $m)) {
      $hour   = (int) $m[1];
      $minute = isset($m[2]) ? (int) $m[2] : 0;
      $ampm   = strtolower($m[3] ?? '');
      if ($ampm === 'pm' && $hour < 12) $hour += 12;
      if ($ampm === 'am' && $hour === 12) $hour = 0;
      $config['time'] = sprintf('%02d:%02d', $hour, $minute);
    } elseif ($frequency === 'daily') {
      $config['time'] = '09:00';
    }

    // Parse balance threshold
    if ($frequency === 'balance') {
      $amount = $this->extractAmount($text);
      if ($amount) {
        $config['min_amount'] = $amount['value'];
      }
    }

    // Every X hours/minutes
    if (preg_match('/every\s+(\d+)\s+hour/i', $text, $m)) {
      $frequency = 'hourly';
      $config['interval_hours'] = (int) $m[1];
    }
    if (preg_match('/every\s+(\d+)\s+minute/i', $text, $m)) {
      $frequency = 'interval';
      $config['interval_minutes'] = (int) $m[1];
    }

    return [
      'type'   => $frequency,
      'config' => $config,
      'label'  => $this->triggerLabel($frequency, $config),
    ];
  }

  // ── Action parsing ────────────────────────────────────────────────────────

  private function parseActions(string $text, array $contacts, array $wallets): array
  {
    $actions = [];

    // Split on conjunctions to find multiple actions
    $segments = preg_split('/\band\b|\bthen\b|\balso\b|\bafter that\b/i', $text);

    foreach ($segments as $segment) {
      $segment = trim($segment);
      if (empty($segment)) continue;

      $action = $this->parseOneAction($segment, $text, $contacts, $wallets);
      if ($action) {
        $actions[] = $action;
      }
    }

    // If no actions found from segments, try the whole text
    if (empty($actions)) {
      $action = $this->parseOneAction($text, $text, $contacts, $wallets);
      if ($action) $actions[] = $action;
    }

    return $actions;
  }

  private function parseOneAction(string $segment, string $fullText, array $contacts, array $wallets): ?array
  {
    $type   = $this->detectActionType($segment);
    if (!$type) return null;

    $amount = $this->extractAmount($segment) ?? $this->extractAmount($fullText);
    $config = [];
    $label  = '';

    if ($type === 'convert_crypto') {
      $token   = $this->detectCryptoToken($segment) ?? 'USDT';
      $network = $this->detectNetwork($segment) ?? $this->detectNetwork($fullText) ?? 'trc20';

      // Find destination wallet
      $wallet = $this->matchWallet($segment, $wallets) ?? $this->matchWallet($fullText, $wallets);

      $config = [
        'token'   => $token,
        'network' => $network,
        'wallet'  => $wallet ? $wallet['address'] : null,
        'wallet_label' => $wallet ? $wallet['label'] : null,
        'wallet_id'    => $wallet ? $wallet['id'] : null,
      ];
      $label = "Convert to {$token} ({$this->networkLabel($network)})";
      if ($wallet) $label .= " → {$wallet['label']}";
    } elseif ($type === 'send_bank') {
      $contact = $this->matchContact($segment, $contacts) ?? $this->matchContact($fullText, $contacts);
      $config  = [
        'contact_id' => $contact ? $contact['id'] : null,
        'contact_label' => $contact ? $contact['label'] : null,
        'narration'  => 'Atlas transfer',
      ];
      $label = $contact ? "Send to {$contact['label']}" : "Bank transfer";
    } elseif ($type === 'save_piggyvest') {
      $plan  = $this->extractQuotedOrAfter($segment, ['to piggyvest', 'in piggyvest', 'piggyvest']) ?? 'Savings';
      $config = ['plan' => $plan, 'note' => 'Atlas auto-save'];
      $label  = "Save to PiggyVest ({$plan})";
    } elseif ($type === 'save_cowrywise') {
      $plan  = $this->extractQuotedOrAfter($segment, ['to cowrywise', 'in cowrywise', 'cowrywise']) ?? 'Investment';
      $config = ['plan' => $plan];
      $label  = "Save to Cowrywise ({$plan})";
    } elseif ($type === 'pay_bill') {
      $provider = $this->detectBillProvider($segment) ?? $this->detectBillProvider($fullText);
      $config   = ['provider' => $provider ?? 'dstv'];
      $label    = "Pay " . strtoupper($provider ?? 'bill');
    }

    if (!$amount) return null;

    return [
      'action_type' => $type,
      'amount_type' => $amount['type'],
      'amount'      => $amount['value'],
      'label'       => $label,
      'config'      => $config,
    ];
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  private function detectActionType(string $text): ?string
  {
    // Check specific patterns first (most specific to least)
    foreach (['pay_bill', 'save_piggyvest', 'save_cowrywise', 'convert_crypto', 'send_bank'] as $type) {
      foreach ($this->actionPatterns[$type] as $pattern) {
        if (str_contains($text, $pattern)) {
          return $type;
        }
      }
    }
    return null;
  }

  private function extractAmount(string $text): ?array
  {
    // Percentage: "30%", "30 percent", "half" (50%), "quarter" (25%)
    if (preg_match('/(\d+(?:\.\d+)?)\s*%/i', $text, $m)) {
      return ['type' => 'percentage', 'value' => (float) $m[1]];
    }
    if (str_contains($text, 'half')) {
      return ['type' => 'percentage', 'value' => 50];
    }
    if (str_contains($text, 'quarter')) {
      return ['type' => 'percentage', 'value' => 25];
    }
    if (str_contains($text, 'all') || str_contains($text, 'everything') || str_contains($text, 'full balance')) {
      return ['type' => 'full_balance', 'value' => null];
    }

    // Fixed: ₦2000, N2000, 2000 naira, 2,000, 2k, 2m
    if (preg_match('/[₦n#]?\s*(\d{1,3}(?:,\d{3})*(?:\.\d+)?)\s*(?:naira|ngn)?/i', $text, $m)) {
      $val = (float) str_replace(',', '', $m[1]);
      if ($val > 0) return ['type' => 'fixed', 'value' => $val];
    }
    // 2k = 2000, 2m = 2000000
    if (preg_match('/(\d+(?:\.\d+)?)\s*k\b/i', $text, $m)) {
      return ['type' => 'fixed', 'value' => (float) $m[1] * 1000];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*m\b/i', $text, $m)) {
      return ['type' => 'fixed', 'value' => (float) $m[1] * 1000000];
    }
    // Plain number
    if (preg_match('/\b(\d{3,})\b/', $text, $m)) {
      return ['type' => 'fixed', 'value' => (float) $m[1]];
    }

    return null;
  }

  private function detectNetwork(string $text): ?string
  {
    foreach ($this->networkPatterns as $network => $patterns) {
      foreach ($patterns as $pattern) {
        if (str_contains($text, $pattern)) return $network;
      }
    }
    return null;
  }

  private function detectCryptoToken(string $text): ?string
  {
    foreach ($this->cryptoTokens as $token => $patterns) {
      foreach ($patterns as $pattern) {
        if (str_contains($text, $pattern)) return $token;
      }
    }
    return null;
  }

  private function detectBillProvider(string $text): ?string
  {
    foreach ($this->billPatterns as $provider => $patterns) {
      foreach ($patterns as $pattern) {
        if (str_contains($text, $pattern)) return $provider;
      }
    }
    return null;
  }

  private function matchContact(string $text, array $contacts): ?array
  {
    foreach ($contacts as $contact) {
      $label = strtolower($contact['label']);
      // Match "mama", "mum", "mom", "wife", partial matches
      if (str_contains($text, $label)) return $contact;
      // Try each word in label
      foreach (explode(' ', $label) as $word) {
        if (strlen($word) > 2 && str_contains($text, $word)) return $contact;
      }
    }
    return null;
  }

  private function matchWallet(string $text, array $wallets): ?array
  {
    foreach ($wallets as $wallet) {
      $label = strtolower($wallet['label']);
      if (str_contains($text, $label)) return $wallet;
      foreach (explode(' ', $label) as $word) {
        if (strlen($word) > 2 && str_contains($text, $word)) return $wallet;
      }
    }
    return null;
  }

  private function extractQuotedOrAfter(string $text, array $keywords): ?string
  {
    // Quoted string: "Emergency Fund"
    if (preg_match('/["\']([^"\']+)["\']/', $text, $m)) return $m[1];
    // After keyword
    foreach ($keywords as $kw) {
      if (preg_match('/' . preg_quote($kw, '/') . '\s+(?:called\s+)?([a-zA-Z\s]+?)(?:\s+and|\s+then|$)/i', $text, $m)) {
        return trim($m[1]);
      }
    }
    return null;
  }

  private function networkLabel(string $network): string
  {
    return match ($network) {
      'bep20'    => 'BEP-20',
      'trc20'    => 'TRC-20',
      'erc20'    => 'ERC-20',
      'polygon'  => 'Polygon',
      'solana'   => 'Solana',
      'arbitrum' => 'Arbitrum',
      'base'     => 'Base',
      default    => strtoupper($network),
    };
  }

  private function triggerLabel(string $type, array $config): string
  {
    return match ($type) {
      'hourly'  => isset($config['interval_hours'])
        ? "Every {$config['interval_hours']} hour(s)"
        : "Every hour",
      'daily'   => "Every day" . (isset($config['time']) ? " at {$config['time']}" : ""),
      'weekly'  => "Every " . ($config['day_name'] ?? 'week'),
      'monthly' => "Every month on the " . ($config['day'] ?? 1) . ordinal($config['day'] ?? 1),
      'deposit' => "When salary/deposit arrives",
      'balance' => "When balance exceeds ₦" . number_format($config['min_amount'] ?? 0),
      'interval' => "Every {$config['interval_minutes']} minutes",
      default   => "Manual trigger",
    };
  }

  private function generateName(string $ruleText, array $trigger, array $actions): string
  {
    // Take first action label + trigger frequency
    $actionLabel = $actions[0]['label'] ?? 'Rule';
    $freq = match ($trigger['type']) {
      'hourly'  => 'Hourly',
      'daily'   => 'Daily',
      'weekly'  => 'Weekly',
      'monthly' => 'Monthly',
      'deposit' => 'On Salary',
      'balance' => 'Balance',
      default   => 'Manual',
    };
    return "{$freq} — {$actionLabel}";
  }

  private function generateSummary(array $trigger, array $actions): string
  {
    $parts = [$trigger['label']];
    foreach ($actions as $i => $a) {
      $amt = $a['amount_type'] === 'percentage'
        ? "{$a['amount']}%"
        : "₦" . number_format($a['amount']);
      $parts[] = ($i + 1) . ". {$a['label']} ({$amt})";
    }
    return implode("\n", $parts);
  }

  private function generateWarnings(array $trigger, array $actions, string $text): array
  {
    $warnings = [];

    foreach ($actions as $a) {
      if ($a['action_type'] === 'send_bank' && empty($a['config']['contact_id'])) {
        $warnings[] = "Couldn't match a saved contact. Please select the recipient manually.";
      }
      if ($a['action_type'] === 'convert_crypto' && empty($a['config']['wallet'])) {
        $warnings[] = "No saved wallet matched. Please select or add a destination wallet.";
      }
      if (empty($a['amount'])) {
        $warnings[] = "Couldn't detect the amount. Please specify it manually.";
      }
    }

    if (empty($actions)) {
      $warnings[] = "Couldn't detect what action to take. Try being more specific.";
    }

    return $warnings;
  }

  private function calculateConfidence(array $trigger, array $actions, string $text): int
  {
    $score = 0;

    if ($trigger['type'] !== 'manual') $score += 30;
    if (!empty($actions)) $score += 30;
    foreach ($actions as $a) {
      if (!empty($a['amount'])) $score += 10;
      if (!empty($a['config']['contact_id']) || !empty($a['config']['wallet'])) $score += 10;
      if (!empty($a['config']['network'])) $score += 5;
    }

    return min(100, $score);
  }
}

// Helper outside class
if (!function_exists('ordinal')) {
  function ordinal(int $n): string
  {
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $s[($v - 20) % 10] ?? $s[$v] ?? $s[0];
  }
}
