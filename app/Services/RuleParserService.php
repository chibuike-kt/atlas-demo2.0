<?php

namespace App\Services;

/**
 * RuleParserService
 *
 * Parses plain English rule text into structured rule config.
 * Designed to be swapped for Claude API with zero other changes.
 */
class RuleParserService
{
  private array $frequencyPatterns = [
    'interval' => [
      'every minute',
      'every 1 minute',
      'each minute',
      'per minute',
      'every 5 minutes',
      'every 10 minutes',
      'every 15 minutes',
      'every 30 minutes',
      'every 45 minutes',
    ],
    'hourly' => [
      'every hour',
      'hourly',
      'each hour',
      'every 1 hour',
      'once an hour',
      'once every hour',
    ],
    'daily' => [
      'every day',
      'daily',
      'each day',
      'once a day',
      'every morning',
      'every night',
      'every evening',
      'every afternoon',
    ],
    'weekly' => [
      'every week',
      'weekly',
      'each week',
      'once a week',
      'every monday',
      'every tuesday',
      'every wednesday',
      'every thursday',
      'every friday',
      'every saturday',
      'every sunday',
    ],
    'monthly' => [
      'every month',
      'monthly',
      'each month',
      'once a month',
    ],
    'manual' => [
      'manually',
      'manual',
      'on demand',
      'when i say',
    ],
    'deposit' => [
      'when salary',
      'on salary',
      'when i get paid',
      'when deposit',
      'on deposit',
      'when money comes in',
      'when i receive',
      'when payment comes',
      'on payday',
      'on pay day',
    ],
    'balance' => [
      'when balance',
      'when my balance',
      'if balance',
      'if my balance',
      'when account balance',
      'whenever balance',
    ],
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

  private array $actionPatterns = [
    'pay_bill' => [
      'pay dstv',
      'pay gotv',
      'pay startimes',
      'pay electricity',
      'pay nepa',
      'buy airtime',
      'pay bill',
      'pay my bill',
      'pay mtn',
      'pay airtel',
      'pay glo',
      'pay 9mobile',
      'recharge mtn',
      'recharge airtel',
      'recharge glo',
    ],
    'save_piggyvest' => [
      'save to piggyvest',
      'piggyvest',
      'piggybank',
      'piggy vest',
      'save to piggy',
      'put in piggyvest',
      'invest in piggyvest',
    ],
    'save_cowrywise' => [
      'cowrywise',
      'cowry wise',
      'save to cowrywise',
      'invest in cowrywise',
      'put in cowrywise',
    ],
    'convert_crypto' => [
      'convert to',
      'convert',
      'swap to',
      'swap',
      'buy usdt',
      'buy btc',
      'buy eth',
      'buy bnb',
      'buy crypto',
      'exchange to',
      'change to',
      'move to crypto',
      'into usdt',
      'to usdt',
      'to btc',
      'to eth',
      'to bnb',
      'to sol',
    ],
    'send_bank' => [
      'send to',
      'transfer to',
      'pay',
      'give',
      'send',
      'wire to',
      'move to',
    ],
  ];

  private array $networkPatterns = [
    'bep20'    => ['bep20', 'bep-20', 'bsc', 'binance smart chain', 'binance chain', 'bnb chain'],
    'trc20'    => ['trc20', 'trc-20', 'tron', 'trx'],
    'erc20'    => ['erc20', 'erc-20', 'ethereum', 'eth chain', 'eth network'],
    'polygon'  => ['polygon', 'matic', 'pol network'],
    'solana'   => ['solana', 'sol network', 'sol chain'],
    'arbitrum' => ['arbitrum', 'arb', 'arb network'],
    'base'     => ['base chain', 'base network'],
  ];

  private array $billPatterns = [
    'dstv'      => ['dstv'],
    'gotv'      => ['gotv', 'go tv'],
    'startimes' => ['startimes', 'star times'],
    'ekedc'     => ['ekedc', 'eko electricity', 'eko electric'],
    'ikedc'     => ['ikedc', 'ikeja electric', 'ikeja electricity'],
    'aedc'      => ['aedc', 'abuja electricity', 'abuja electric'],
    'mtn'       => ['mtn airtime', 'mtn recharge', 'mtn data', 'recharge mtn'],
    'airtel'    => ['airtel airtime', 'airtel recharge', 'recharge airtel'],
    'glo'       => ['glo airtime', 'glo recharge', 'recharge glo'],
    '9mobile'   => ['9mobile', 'etisalat'],
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
    $text = strtolower(trim($ruleText));

    $trigger = $this->parseTrigger($text);
    $actions = $this->parseActions($text, $contacts, $wallets);
    $name    = $this->generateName($ruleText, $trigger, $actions);

    return [
      'understood' => true,
      'confidence' => $this->calculateConfidence($trigger, $actions, $text),
      'name'       => $name,
      'rule_text'  => $ruleText,
      'trigger'    => $trigger,
      'actions'    => $actions,
      'summary'    => $this->generateSummary($trigger, $actions),
      'warnings'   => $this->generateWarnings($trigger, $actions, $text),
    ];
  }

  // ── Trigger parsing ───────────────────────────────────────────────────────

  private function parseTrigger(string $text): array
  {
    $frequency = 'manual';
    $config    = [];

    // Check frequency patterns — order matters (interval before hourly before daily)
    foreach ($this->frequencyPatterns as $freq => $patterns) {
      foreach ($patterns as $pattern) {
        if (str_contains($text, $pattern)) {
          $frequency = $freq;
          break 2;
        }
      }
    }

    // "on the 25th" or "25th of each month" → monthly
    if (preg_match('/on the (\d{1,2})(st|nd|rd|th)?/i', $text, $m)) {
      $frequency   = 'monthly';
      $config['day'] = (int) $m[1];
    } elseif (preg_match('/(\d{1,2})(st|nd|rd|th)?\s+of\s+(each|every|the)\s+month/i', $text, $m)) {
      $frequency   = 'monthly';
      $config['day'] = (int) $m[1];
    }

    // Monthly with no day specified → default day 1
    if ($frequency === 'monthly' && empty($config['day'])) {
      $config['day'] = 1;
    }

    // Weekly — find which day
    if ($frequency === 'weekly') {
      foreach ($this->dayNames as $name => $num) {
        if (str_contains($text, $name)) {
          $config['day_of_week'] = $num;
          $config['day_name']    = ucfirst($name);
          break;
        }
      }
    }

    // Every X minutes
    if (preg_match('/every\s+(\d+)\s+minute/i', $text, $m)) {
      $frequency = 'interval';
      $config['interval_minutes'] = (int) $m[1];
    } elseif (str_contains($text, 'every minute') || str_contains($text, 'each minute') || str_contains($text, 'per minute')) {
      $frequency = 'interval';
      $config['interval_minutes'] = 1;
    }

    // Every X hours
    if (preg_match('/every\s+(\d+)\s+hour/i', $text, $m)) {
      $frequency = 'hourly';
      $config['interval_hours'] = (int) $m[1];
    } elseif ($frequency === 'hourly' && empty($config['interval_hours'])) {
      $config['interval_hours'] = 1;
    }

    // Time: "at 9am", "at 14:30"
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

    // Balance threshold
    if ($frequency === 'balance') {
      $amount = $this->extractAmount($text);
      if ($amount) {
        $config['min_amount'] = $amount['value'];
      }
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
    // Skip segments that are just wallet destinations
    if (
      preg_match('/\bwallet\b|\baddress\b/i', $segment) &&
      !preg_match('/\bbank\b|\btransfer\b|\bcontact\b/i', $segment)
    ) {
      return null;
    }

    $type = $this->detectActionType($segment);
    if (!$type) return null;

    $amount = $this->extractAmount($segment) ?? $this->extractAmount($fullText);
    $config = [];
    $label  = '';

    if ($type === 'convert_crypto') {
      $token   = $this->detectCryptoToken($segment) ?? $this->detectCryptoToken($fullText) ?? 'USDT';
      $network = $this->detectNetwork($segment) ?? $this->detectNetwork($fullText) ?? 'trc20';
      $wallet  = $this->matchWallet($segment, $wallets) ?? $this->matchWallet($fullText, $wallets);

      $config = [
        'token'        => $token,
        'network'      => $network,
        'wallet'       => $wallet['address'] ?? null,
        'wallet_label' => $wallet['label'] ?? null,
        'wallet_id'    => $wallet['id'] ?? null,
      ];
      $label = "Convert to {$token} ({$this->networkLabel($network)})";
      if ($wallet) $label .= " → {$wallet['label']}";
    } elseif ($type === 'send_bank') {
      $contact = $this->matchContact($segment, $contacts) ?? $this->matchContact($fullText, $contacts);
      $config  = [
        'contact_id'    => $contact['id'] ?? null,
        'contact_label' => $contact['label'] ?? null,
        'narration'     => 'Atlas transfer',
      ];
      $label = $contact ? "Send to {$contact['label']}" : "Bank transfer";
    } elseif ($type === 'save_piggyvest') {
      $plan   = $this->extractPlanName($segment, ['to piggyvest', 'in piggyvest', 'piggyvest']) ?? 'Savings';
      $config = ['plan' => $plan, 'note' => 'Atlas auto-save'];
      $label  = "Save to PiggyVest ({$plan})";
    } elseif ($type === 'save_cowrywise') {
      $plan   = $this->extractPlanName($segment, ['to cowrywise', 'in cowrywise', 'cowrywise']) ?? 'Investment';
      $config = ['plan' => $plan];
      $label  = "Save to Cowrywise ({$plan})";
    } elseif ($type === 'pay_bill') {
      $provider = $this->detectBillProvider($segment) ?? $this->detectBillProvider($fullText) ?? 'dstv';
      $config   = ['provider' => $provider];
      $label    = "Pay " . strtoupper($provider);
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
    // Most specific first to avoid false matches
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
    // Full balance keywords
    if (preg_match('/\b(all|everything|full balance|entire balance|whole balance)\b/i', $text)) {
      return ['type' => 'full_balance', 'value' => null];
    }

    // Half / quarter shortcuts
    if (preg_match('/\bhalf\b/i', $text)) {
      return ['type' => 'percentage', 'value' => 50];
    }
    if (preg_match('/\bquarter\b/i', $text)) {
      return ['type' => 'percentage', 'value' => 25];
    }

    // Percentage: "30%", "30 percent"
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:%|percent)/i', $text, $m)) {
      return ['type' => 'percentage', 'value' => (float) $m[1]];
    }

    // Shorthand: 2k, 1.5m
    if (preg_match('/(\d+(?:\.\d+)?)\s*k\b/i', $text, $m)) {
      return ['type' => 'fixed', 'value' => (float) $m[1] * 1000];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*m\b/i', $text, $m)) {
      return ['type' => 'fixed', 'value' => (float) $m[1] * 1_000_000];
    }

    // Currency prefix: ₦5000, N5000, #5000
    if (preg_match('/[₦nN#]\s*(\d{1,9}(?:,\d{3})*(?:\.\d+)?)/u', $text, $m)) {
      $val = (float) str_replace(',', '', $m[1]);
      if ($val > 0) return ['type' => 'fixed', 'value' => $val];
    }

    // Plain number (3+ digits to avoid matching days/times)
    if (preg_match('/\b(\d{3,9}(?:,\d{3})*(?:\.\d+)?)\b/', $text, $m)) {
      $val = (float) str_replace(',', '', $m[1]);
      // Exclude values that look like years or times
      if ($val > 0 && ($val < 1900 || $val > 2100)) {
        return ['type' => 'fixed', 'value' => $val];
      }
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
      $label = strtolower($contact['label'] ?? '');
      if (empty($label)) continue;
      if (str_contains($text, $label)) return $contact;
      foreach (explode(' ', $label) as $word) {
        if (strlen($word) > 2 && str_contains($text, $word)) return $contact;
      }
    }
    return null;
  }

  private function matchWallet(string $text, array $wallets): ?array
  {
    foreach ($wallets as $wallet) {
      $label = strtolower($wallet['label'] ?? '');
      if (empty($label)) continue;
      if (str_contains($text, $label)) return $wallet;
      foreach (explode(' ', $label) as $word) {
        if (strlen($word) > 2 && str_contains($text, $word)) return $wallet;
      }
    }
    return null;
  }

  private function extractPlanName(string $text, array $keywords): ?string
  {
    // Quoted: "Emergency Fund"
    if (preg_match('/["\']([^"\']+)["\']/', $text, $m)) return $m[1];
    // After keyword: "save to piggyvest emergency fund"
    foreach ($keywords as $kw) {
      if (preg_match('/' . preg_quote($kw, '/') . '\s+(?:called\s+|named\s+)?([a-z][a-z\s]{2,30}?)(?:\s+and|\s+then|$)/i', $text, $m)) {
        $name = trim($m[1]);
        if (!empty($name)) return ucwords($name);
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
      'interval' => isset($config['interval_minutes'])
        ? ($config['interval_minutes'] === 1 ? 'Every minute' : "Every {$config['interval_minutes']} minutes")
        : 'Every interval',
      'hourly'   => isset($config['interval_hours']) && $config['interval_hours'] > 1
        ? "Every {$config['interval_hours']} hours"
        : 'Every hour',
      'daily'    => 'Every day' . (isset($config['time']) ? " at {$config['time']}" : ''),
      'weekly'   => 'Every ' . ($config['day_name'] ?? 'week'),
      'monthly'  => 'Every month on the ' . ($config['day'] ?? 1) . $this->ordinal($config['day'] ?? 1),
      'deposit'  => 'When salary / deposit arrives',
      'balance'  => 'When balance exceeds ₦' . number_format($config['min_amount'] ?? 0),
      'manual'   => 'Manual trigger',
      default    => 'Manual trigger',
    };
  }

  private function generateName(string $ruleText, array $trigger, array $actions): string
  {
    $actionLabel = $actions[0]['label'] ?? 'Rule';
    $freq = match ($trigger['type']) {
      'interval' => 'Every-Minute',
      'hourly'   => 'Hourly',
      'daily'    => 'Daily',
      'weekly'   => 'Weekly',
      'monthly'  => 'Monthly',
      'deposit'  => 'On Salary',
      'balance'  => 'Balance',
      default    => 'Manual',
    };
    return "{$freq} — {$actionLabel}";
  }

  private function generateSummary(array $trigger, array $actions): string
  {
    $parts = [$trigger['label']];
    foreach ($actions as $i => $a) {
      $amt = $a['amount_type'] === 'percentage'
        ? "{$a['amount']}%"
        : '₦' . number_format($a['amount'] ?? 0);
      $parts[] = ($i + 1) . ". {$a['label']} ({$amt})";
    }
    return implode("\n", $parts);
  }

  private function generateWarnings(array $trigger, array $actions, string $text): array
  {
    $warnings = [];

    if (empty($actions)) {
      $warnings[] = "Couldn't detect what action to take. Try being more specific, e.g. \"send ₦5000 to mama\" or \"convert ₦2000 to USDT on BEP-20\".";
      return $warnings;
    }

    foreach ($actions as $a) {
      if ($a['action_type'] === 'send_bank' && empty($a['config']['contact_id'])) {
        $warnings[] = "Couldn't match a saved contact. Add the recipient to your contacts first, or check the spelling.";
      }
      if ($a['action_type'] === 'convert_crypto' && empty($a['config']['wallet'])) {
        $warnings[] = "No saved wallet matched. Add a crypto wallet in Contacts → Add Wallet, then Atlas will auto-match it.";
      }
      if (empty($a['amount']) && $a['amount_type'] !== 'full_balance') {
        $warnings[] = "Couldn't detect the amount for \"{$a['label']}\". Include a number like ₦5000 or 30%.";
      }
    }

    return $warnings;
  }

  private function calculateConfidence(array $trigger, array $actions, string $text): int
  {
    $score = 0;
    if ($trigger['type'] !== 'manual') $score += 30;
    if (!empty($actions)) $score += 25;
    foreach ($actions as $a) {
      if (!empty($a['amount'])) $score += 15;
      if (!empty($a['config']['contact_id']) || !empty($a['config']['wallet'])) $score += 15;
      if (!empty($a['config']['network'])) $score += 5;
      if (!empty($a['config']['plan']) || !empty($a['config']['provider'])) $score += 5;
    }
    return min(100, $score);
  }

  private function ordinal(int $n): string
  {
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $s[($v - 20) % 10] ?? $s[$v] ?? $s[0];
  }
}
