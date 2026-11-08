<?php

namespace Database\Seeders;

use App\Models\GameCard;
use Illuminate\Database\Seeder;

class GameCardSeeder extends Seeder
{
    private const LEVELS = ['Leve' => 'L', 'Mais perto' => 'M', 'Intenso' => 'I'];

    /** Lê os .md de docs/conteudo (fonte única). */
    public function run(): void
    {
        $dir = config('love.content_dir');
        $this->quem("{$dir}/quem-do-casal.md");
        $this->noites("{$dir}/baralho-da-rotina.md");
        $this->vod("{$dir}/verdade-ou-desafio.md");
        $this->choices("{$dir}/quebra-gelo.md", 'ice');
        $this->choices("{$dir}/voce-me-conhece.md", 'yk');
        $this->dados("{$dir}/dados-picantes.md");
        $this->board("{$dir}/tabuleiro.md");
    }

    /** Tabuleiro: tabela "| casa | tipo | cartão | nível |" sob "## Casas". */
    private function board(string $file): void
    {
        $in = false;
        foreach ($this->lines($file) as $line) {
            if (str_starts_with($line, '## ')) {
                $in = str_contains($line, 'Casas');
            } elseif ($in && preg_match('/^\|\s*(\d+)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*([LMI])\s*\|$/u', $line, $m)) {
                $text = $m[3];
                $extra = ['type' => $m[2]];

                if (preg_match('/^(.+?)\s*→\s*(.+)$/u', $text, $p)) {
                    $text = $p[1];
                    $extra['options'] = array_map('trim', explode(' / ', $p[2]));
                    if (! in_array('Prefiro não responder', $extra['options'], true)) {
                        $extra['options'][] = 'Prefiro não responder';
                    }
                }
                if (str_contains($text, '(cronômetro)')) {
                    $text = trim(str_replace('(cronômetro)', '', $text));
                    $extra['timer_seconds'] = 60;
                    if (preg_match('/(\d+)\s*(segundos?|minutos?)/u', $text, $t)) {
                        $extra['timer_seconds'] = (int) $t[1] * (str_starts_with($t[2], 'minuto') ? 60 : 1);
                    }
                }
                if ($m[2] === 'Sorte') {
                    $words = ['uma' => 1, 'duas' => 2, 'três' => 3];
                    if (preg_match('/Avance (uma|duas|três) casas?/u', $text, $e)) {
                        $extra['delta'] = $words[$e[1]];
                    } elseif (preg_match('/Volte (uma|duas|três) casas?/u', $text, $e)) {
                        $extra['delta'] = -$words[$e[1]];
                    }
                }

                GameCard::firstOrCreate(
                    ['game' => 'board', 'number' => (int) $m[1]],
                    ['level' => $m[4], 'text' => $text, 'extra' => $extra],
                );
            }
        }
    }

    /** Perguntas "texto → a / b / c". Acrescenta "Prefiro não responder" quando faltar. */
    private function choices(string $file, string $game): void
    {
        $level = $game === 'ice' ? 'L' : null;
        foreach ($this->lines($file) as $line) {
            if (str_starts_with($line, '## ')) {
                $level = $game === 'ice' ? 'L' : $this->levelOf($line);
            } elseif ($level && preg_match('/^(\d+)\.\s+(.+?)\s*→\s*(.+)$/u', $line, $m)) {
                $options = array_map('trim', explode(' / ', $m[3]));
                if (! in_array('Prefiro não responder', $options, true)) {
                    $options[] = 'Prefiro não responder';
                }
                GameCard::firstOrCreate(
                    ['game' => $game, 'number' => (int) $m[1]],
                    ['level' => $level, 'text' => $m[2], 'extra' => ['options' => $options]],
                );
            }
        }
    }

    /** Dados picantes: 3 faces por linha (ação, região, duração). Versão à distância vira "dado_remoto". */
    private function dados(string $file): void
    {
        $game = null;
        $offset = 0;
        foreach ($this->lines($file) as $line) {
            if (str_starts_with($line, '## ')) {
                $level = $this->levelOf($line);
                if ($level) {
                    $game = 'dado';
                    $offset = ['L' => 0, 'M' => 100, 'I' => 200][$level];
                } elseif (str_contains($line, 'distância')) {
                    $game = 'dado_remoto';
                    $level = 'L';
                    $offset = 0;
                } else {
                    $game = null;
                }
            } elseif ($game && preg_match('/^\|\s*(\d+)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|$/u', $line, $m)) {
                $seconds = 60;
                if (preg_match('/(\d+)\s*(segundos?|minutos?)/u', $m[4], $t)) {
                    $seconds = (int) $t[1] * (str_starts_with($t[2], 'minuto') ? 60 : 1);
                }
                GameCard::firstOrCreate(
                    ['game' => $game, 'number' => (int) $m[1] + $offset],
                    ['level' => $level, 'text' => $m[2], 'extra' => [
                        'action' => $m[2],
                        'region' => trim($m[3], '"'),
                        'duration' => $m[4],
                        'seconds' => $seconds,
                    ]],
                );
            }
        }
    }

    /** Verdade ou Desafio: a numeração reinicia por nível, então soma 100 por nível (L=0, M=100, I=200). */
    private function vod(string $file): void
    {
        $level = null;
        $kind = null;
        foreach ($this->lines($file) as $line) {
            if (str_starts_with($line, '## ')) {
                $level = $this->levelOf($line);
                $kind = null;
            } elseif (preg_match('/^###\s+(Verdades|Desafios|Surpresas)/u', $line, $m)) {
                $kind = ['Verdades' => 'verdade', 'Desafios' => 'desafio', 'Surpresas' => 'surpresa'][$m[1]];
            } elseif ($level && $kind && preg_match('/^(\d+)\.\s+(.+)$/u', $line, $m)) {
                $number = (int) $m[1] + ['L' => 0, 'M' => 100, 'I' => 200][$level];
                $text = $m[2];
                $extra = [];

                if ($kind === 'verdade') {
                    if (! preg_match('/^(.+?)\s*→\s*(.+)$/u', $text, $p)) {
                        continue;
                    }
                    $text = $p[1];
                    $extra['options'] = array_map('trim', explode(' / ', $p[2]));
                } else {
                    if ($kind === 'desafio' && preg_match('/^(.+?)\s*\|\s*À distância:\s*(.+)$/u', $text, $p)) {
                        $text = $p[1];
                        $extra['remote'] = $p[2];
                    }
                    if (str_contains($text, '(cronômetro)')) {
                        $text = trim(str_replace('(cronômetro)', '', $text));
                        $seconds = 60;
                        if (preg_match('/(\d+)\s*(segundos?|minutos?)/u', $text, $t)) {
                            $seconds = (int) $t[1] * (str_starts_with($t[2], 'minuto') ? 60 : 1);
                        }
                        $extra['timer_seconds'] = $seconds;
                    }
                }

                GameCard::firstOrCreate(
                    ['game' => "vod_{$kind}", 'number' => $number],
                    ['level' => $level, 'text' => $text, 'extra' => $extra],
                );
            }
        }
    }

    private function lines(string $file): array
    {
        if (! is_file($file)) {
            throw new \RuntimeException("Conteúdo não encontrado: {$file}");
        }

        return file($file, FILE_IGNORE_NEW_LINES);
    }

    private function levelOf(string $line): ?string
    {
        if (preg_match('/^##\s+(Leve|Mais perto|Intenso)\b/u', $line, $m)) {
            return self::LEVELS[$m[1]];
        }

        return null;
    }

    private function quem(string $file): void
    {
        $level = null;
        foreach ($this->lines($file) as $line) {
            if (str_starts_with($line, '## ')) {
                $level = $this->levelOf($line);
            } elseif ($level && preg_match('/^(\d+)\.\s+(.+\?)$/u', $line, $m)) {
                GameCard::firstOrCreate(
                    ['game' => 'quem', 'number' => (int) $m[1]],
                    ['level' => $level, 'text' => $m[2]],
                );
            }
        }
    }

    private function noites(string $file): void
    {
        $level = null;
        foreach ($this->lines($file) as $line) {
            if (str_starts_with($line, '## ')) {
                $level = $this->levelOf($line);
            } elseif ($level && preg_match('/^\|\s*(\d+)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|$/u', $line, $m)) {
                GameCard::firstOrCreate(
                    ['game' => 'noite', 'number' => (int) $m[1]],
                    ['level' => $level, 'text' => $m[2], 'extra' => ['in_person' => $m[3], 'remote' => $m[4]]],
                );
            }
        }
    }
}
