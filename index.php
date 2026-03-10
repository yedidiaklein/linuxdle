<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

$dbHost = getenv('LINUXDLE_DB_HOST') ?: 'localhost';
$dbUser = getenv('LINUXDLE_DB_USER') ?: 'linuxdle';
$dbPass = getenv('LINUXDLE_DB_PASS') ?: 'linuxdle';
$dbName = getenv('LINUXDLE_DB_NAME') ?: 'linuxdle';
$dbPort = (int) (getenv('LINUXDLE_DB_PORT') ?: 3306);

$error = null;
$distros = [];

try {
	$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
	if ($mysqli->connect_errno) {
		throw new RuntimeException('MySQL connection failed: ' . $mysqli->connect_error);
	}

	$query = "
		SELECT id, name, image, url
		FROM distros
		WHERE image IS NOT NULL
		  AND TRIM(image) <> ''
		ORDER BY name ASC
	";

	$result = $mysqli->query($query);
	if (!$result) {
		throw new RuntimeException('Query failed: ' . $mysqli->error);
	}

	while ($row = $result->fetch_assoc()) {
		$image = trim((string) $row['image']);
		$imagePath = __DIR__ . '/static/' . $image;
		if (!is_file($imagePath)) {
			continue;
		}

		$name = trim((string) $row['name']);
		if ($name === '') {
			continue;
		}

		$distros[] = [
			'id' => (int) $row['id'],
			'name' => $name,
			'image' => $image,
			'url' => trim((string) $row['url']),
		];
	}

	$result->free();
	$mysqli->close();
} catch (Throwable $exception) {
	$error = $exception->getMessage();
}

if (!$error && count($distros) === 0) {
	$error = 'No distros with valid images were found in the database.';
}

$gamePayload = [
	'date' => gmdate('Y-m-d'),
	'distros' => $distros,
	'maxGuesses' => 6,
];
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Linuxdle</title>
	<link rel="stylesheet" href="static/linuxdle.css">
</head>
<body>
<main class="page-wrap">
	<header class="hero">
		<p class="eyebrow">Daily Linux Distro Puzzle</p>
		<h1>Linuxdle</h1>
		<p class="subtitle">Guess the distro name in six tries. Letters light up just like Wordle.</p>
	</header>

	<?php if ($error !== null): ?>
		<section class="panel error-panel">
			<h2>Setup error</h2>
			<p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
			<p>Check your MySQL settings and ensure image files exist in <code>static/</code>.</p>
		</section>
	<?php else: ?>
		<section class="panel game-panel">
			<div class="game-top">
				<div>
					<p class="tiny-label">Puzzle date</p>
					<p class="value" id="puzzleDate"></p>
				</div>
				<div>
					<p class="tiny-label">Answer length</p>
					<p class="value"><span id="answerLength"></span> letters</p>
				</div>
			</div>

			<div id="toast" class="toast" aria-live="polite"></div>

			<div id="grid" class="grid" aria-label="Guess grid"></div>

			<form id="guessForm" class="guess-form" autocomplete="off">
				<label for="guessSelect">Pick a distro</label>
				<div class="guess-controls">
					<select id="guessSelect" required></select>
					<button id="guessButton" type="submit">Guess</button>
				</div>
			</form>

			<div id="keyboard" class="keyboard" aria-label="Keyboard status"></div>

			<section id="resultCard" class="result-card" hidden>
				<h2 id="resultTitle"></h2>
				<img id="resultImage" class="distro" alt="Distro logo" loading="lazy">
				<p id="resultText"></p>
				<p>
					<a id="resultLink" href="#" target="_blank" rel="noopener noreferrer">Visit distro website</a>
				</p>
				<div class="result-actions">
					<button id="whatsappShare" type="button" hidden>Share to WhatsApp</button>
				</div>
			</section>
		</section>

		<script>
			const payload = <?= json_encode($gamePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

			const normalizeWord = (value) => value.toUpperCase().replace(/[^A-Z]/g, '');
			const maxGuesses = Number(payload.maxGuesses) || 6;
			const toastEl = document.getElementById('toast');
			const gridEl = document.getElementById('grid');
			const keyboardEl = document.getElementById('keyboard');
			const guessSelect = document.getElementById('guessSelect');
			const guessForm = document.getElementById('guessForm');
			const guessButton = document.getElementById('guessButton');
			const resultCard = document.getElementById('resultCard');
			const resultTitle = document.getElementById('resultTitle');
			const resultText = document.getElementById('resultText');
			const resultImage = document.getElementById('resultImage');
			const resultLink = document.getElementById('resultLink');
			const whatsappShare = document.getElementById('whatsappShare');

			const showToast = (message) => {
				toastEl.textContent = message;
				toastEl.classList.add('show');
				window.clearTimeout(showToast.timeoutId);
				showToast.timeoutId = window.setTimeout(() => {
					toastEl.classList.remove('show');
				}, 1800);
			};

			const seededIndex = (isoDate, length) => {
				let hash = 0;
				for (let i = 0; i < isoDate.length; i += 1) {
					hash = ((hash << 5) - hash) + isoDate.charCodeAt(i);
					hash |= 0;
				}
				return Math.abs(hash) % length;
			};

			const evaluateGuess = (guess, answer) => {
				const result = new Array(answer.length).fill('absent');
				const counts = {};

				for (let i = 0; i < answer.length; i += 1) {
					const char = answer[i];
					counts[char] = (counts[char] || 0) + 1;
				}

				for (let i = 0; i < answer.length; i += 1) {
					if (guess[i] === answer[i]) {
						result[i] = 'correct';
						counts[guess[i]] -= 1;
					}
				}

				for (let i = 0; i < answer.length; i += 1) {
					if (result[i] !== 'absent') {
						continue;
					}
					const char = guess[i];
					if (counts[char] > 0) {
						result[i] = 'present';
						counts[char] -= 1;
					}
				}

				return result;
			};

			const rank = { absent: 1, present: 2, correct: 3 };
			const emojiByState = { correct: '🟩', present: '🟨', absent: '⬛' };

			const buildKeyboard = () => {
				const letters = 'QWERTYUIOPASDFGHJKLZXCVBNM'.split('');
				keyboardEl.innerHTML = '';
				letters.forEach((letter) => {
					const key = document.createElement('div');
					key.className = 'key';
					key.dataset.key = letter;
					key.textContent = letter;
					keyboardEl.appendChild(key);
				});
			};

			const createGrid = (rows, cols) => {
				gridEl.innerHTML = '';
				gridEl.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
				for (let row = 0; row < rows; row += 1) {
					for (let col = 0; col < cols; col += 1) {
						const tile = document.createElement('div');
						tile.className = 'tile';
						tile.dataset.row = String(row);
						tile.dataset.col = String(col);
						gridEl.appendChild(tile);
					}
				}
			};

			const paintRow = (row, guess, states, keyState) => {
				for (let i = 0; i < guess.length; i += 1) {
					const selector = `.tile[data-row="${row}"][data-col="${i}"]`;
					const tile = gridEl.querySelector(selector);
					tile.textContent = guess[i];
					tile.classList.add(states[i]);

					const key = keyboardEl.querySelector(`.key[data-key="${guess[i]}"]`);
					const prior = keyState[guess[i]] || 'absent';
					if (rank[states[i]] >= rank[prior]) {
						keyState[guess[i]] = states[i];
						key.classList.remove('absent', 'present', 'correct');
						key.classList.add(states[i]);
					}
				}
			};

			const allDistros = payload.distros.map((item) => ({
				id: item.id,
				name: item.name,
				image: item.image,
				url: item.url,
				normalized: normalizeWord(item.name),
			})).filter((item) => item.normalized.length > 0);

			const answerIndex = seededIndex(payload.date, allDistros.length);
			const answer = allDistros[answerIndex];
			const answerLength = answer.normalized.length;
			const choices = allDistros.filter((item) => item.normalized.length === answerLength);
			const choiceMap = new Map(choices.map((item) => [item.name, item]));

			document.getElementById('puzzleDate').textContent = payload.date;
			document.getElementById('answerLength').textContent = String(answerLength);

			if (choices.length === 0) {
				showToast('No valid guesses for this puzzle length.');
				guessButton.disabled = true;
			}

			choices.forEach((item) => {
				const option = document.createElement('option');
				option.value = item.name;
				option.textContent = item.name;
				guessSelect.appendChild(option);
			});

			let guessCount = 0;
			let gameOver = false;
			const keyState = {};
			const guessPatterns = [];

			buildKeyboard();
			createGrid(maxGuesses, answerLength);

			const buildShareText = (won) => {
				const score = won ? `${guessCount}/${maxGuesses}` : `X/${maxGuesses}`;
				const lines = guessPatterns.map((states) => states.map((state) => emojiByState[state] || '⬛').join(''));
				const shareLines = [`Linuxdle ${payload.date} ${score}`, ...lines];
				return shareLines.join('\n');
			};

			const finishGame = (won) => {
				gameOver = true;
				guessButton.disabled = true;
				guessSelect.disabled = true;

				resultCard.hidden = false;
				resultImage.src = `static/${answer.image}`;
				resultLink.href = answer.url || '#';
				resultLink.hidden = answer.url.trim() === '';

				if (won) {
					resultTitle.textContent = 'Kernel-level victory';
					resultText.textContent = `You solved it in ${guessCount} guess${guessCount === 1 ? '' : 'es'}: ${answer.name}`;
				} else {
					resultTitle.textContent = 'Out of guesses';
					resultText.textContent = `The distro was ${answer.name}. Better luck on tomorrow's build.`;
				}

				const shareText = buildShareText(won);
				whatsappShare.hidden = false;
				whatsappShare.onclick = () => {
					const shareUrl = `https://wa.me/?text=${encodeURIComponent(shareText)}`;
					window.open(shareUrl, '_blank', 'noopener');
				};
			};

			guessForm.addEventListener('submit', (event) => {
				event.preventDefault();
				if (gameOver) {
					return;
				}

				const selectedName = guessSelect.value;
				const picked = choiceMap.get(selectedName);
				if (!picked) {
					showToast('Pick a valid distro from the list.');
					return;
				}

				if (picked.normalized.length !== answerLength) {
					showToast(`Guess must be ${answerLength} letters.`);
					return;
				}

				const states = evaluateGuess(picked.normalized, answer.normalized);
				guessPatterns.push(states);
				paintRow(guessCount, picked.normalized, states, keyState);
				guessCount += 1;

				if (picked.normalized === answer.normalized) {
					finishGame(true);
					return;
				}

				if (guessCount >= maxGuesses) {
					finishGame(false);
					return;
				}
			});
		</script>
	<?php endif; ?>
</main>
</body>
</html>
