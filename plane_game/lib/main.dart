import 'dart:async';
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

void main() {
  runApp(const PlaneDodgeApp());
}

class PlaneDodgeApp extends StatelessWidget {
  const PlaneDodgeApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'پرواز هواپیما',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        brightness: Brightness.dark,
        primarySwatch: Colors.blue,
        scaffoldBackgroundColor: const Color(0xFF0B1E3D),
        fontFamily: 'Arial',
      ),
      home: const HomeScreen(),
    );
  }
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int bestScore = 0;

  void _play() async {
    final result = await Navigator.of(context).push<int>(
      MaterialPageRoute(builder: (_) => const GameScreen()),
    );
    if (result != null && result > bestScore) {
      setState(() => bestScore = result);
    } else {
      setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color(0xFF0B1E3D), Color(0xFF1B4B8F)],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.flight, size: 110, color: Colors.white),
                const SizedBox(height: 16),
                const Text(
                  'پرواز هواپیما',
                  style: TextStyle(
                    fontSize: 34,
                    fontWeight: FontWeight.bold,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(height: 8),
                const Text(
                  'با کشیدن انگشت به چپ و راست از موانع فرار کن',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 16, color: Colors.white70),
                ),
                const SizedBox(height: 32),
                Text(
                  'بهترین امتیاز: $bestScore',
                  style: const TextStyle(fontSize: 18, color: Colors.amber),
                ),
                const SizedBox(height: 32),
                ElevatedButton(
                  onPressed: _play,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.orangeAccent,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 48,
                      vertical: 18,
                    ),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(30),
                    ),
                  ),
                  child: const Text(
                    'شروع بازی',
                    style: TextStyle(fontSize: 22, color: Colors.black87),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class Obstacle {
  double x; // center x, 0..1 fraction of width
  double y; // top position in pixels
  double size;
  Obstacle({required this.x, required this.y, required this.size});
}

class GameScreen extends StatefulWidget {
  const GameScreen({super.key});

  @override
  State<GameScreen> createState() => _GameScreenState();
}

class _GameScreenState extends State<GameScreen>
    with SingleTickerProviderStateMixin {
  late Ticker _ticker;
  Duration _lastTick = Duration.zero;

  double planeX = 0.5; // fraction 0..1
  final double planeWidth = 0.16; // fraction of screen width
  final double planeHeight = 60;

  final List<Obstacle> obstacles = [];
  final Random rng = Random();

  double speed = 220; // px/sec
  double spawnTimer = 0;
  double spawnInterval = 1.1;
  double elapsed = 0;
  int score = 0;
  bool gameOver = false;
  bool started = false;

  Size? screenSize;

  @override
  void initState() {
    super.initState();
    _ticker = createTicker(_onTick)..start();
  }

  void _onTick(Duration elapsedTime) {
    if (screenSize == null || gameOver || !started) {
      _lastTick = elapsedTime;
      return;
    }
    final dt = (elapsedTime - _lastTick).inMicroseconds / 1e6;
    _lastTick = elapsedTime;
    if (dt <= 0 || dt > 0.1) return;

    setState(() {
      elapsed += dt;
      speed = 220 + elapsed * 6; // gradually speed up
      spawnInterval = max(0.45, 1.1 - elapsed * 0.01);

      spawnTimer += dt;
      if (spawnTimer >= spawnInterval) {
        spawnTimer = 0;
        _spawnObstacle();
      }

      for (final o in obstacles) {
        o.y += speed * dt;
      }
      obstacles.removeWhere((o) => o.y > screenSize!.height + 100);

      score = elapsed.floor();

      _checkCollisions();
    });
  }

  void _spawnObstacle() {
    final size = 46.0 + rng.nextDouble() * 24;
    final x = 0.1 + rng.nextDouble() * 0.8;
    obstacles.add(Obstacle(x: x, y: -size, size: size));
  }

  void _checkCollisions() {
    final w = screenSize!.width;
    final h = screenSize!.height;
    final planePxX = planeX * w;
    final planePxY = h - 140;
    final planeR = (planeWidth * w) / 2.4;

    for (final o in obstacles) {
      final oPxX = o.x * w;
      final oPxY = o.y;
      final dist = sqrt(
        pow(planePxX - oPxX, 2) + pow(planePxY - oPxY, 2),
      );
      if (dist < planeR + o.size / 2.4) {
        _endGame();
        return;
      }
    }
  }

  void _endGame() {
    if (gameOver) return;
    gameOver = true;
    Future.delayed(const Duration(milliseconds: 200), () {
      if (mounted) _showGameOverDialog();
    });
  }

  void _showGameOverDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        backgroundColor: const Color(0xFF14294F),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
        ),
        title: const Text(
          'برخورد کردی!',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.white),
        ),
        content: Text(
          'امتیاز تو: $score',
          textAlign: TextAlign.center,
          style: const TextStyle(color: Colors.white70, fontSize: 18),
        ),
        actions: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: [
              TextButton(
                onPressed: () {
                  Navigator.of(context).pop();
                  Navigator.of(context).pop(score);
                },
                child: const Text('خروج'),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.orangeAccent,
                ),
                onPressed: () {
                  Navigator.of(context).pop();
                  _restart();
                },
                child: const Text(
                  'دوباره بازی',
                  style: TextStyle(color: Colors.black87),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  void _restart() {
    setState(() {
      obstacles.clear();
      planeX = 0.5;
      speed = 220;
      spawnTimer = 0;
      spawnInterval = 1.1;
      elapsed = 0;
      score = 0;
      gameOver = false;
      started = true;
    });
  }

  void _onDragUpdate(DragUpdateDetails details) {
    if (screenSize == null || gameOver) return;
    setState(() {
      planeX += details.delta.dx / screenSize!.width;
      planeX = planeX.clamp(0.08, 0.92);
    });
  }

  void _onTapDown(TapDownDetails details) {
    if (!started) {
      setState(() => started = true);
      return;
    }
    if (screenSize == null || gameOver) return;
    final w = screenSize!.width;
    final tapX = details.globalPosition.dx;
    setState(() {
      if (tapX < w / 2) {
        planeX = (planeX - 0.12).clamp(0.08, 0.92);
      } else {
        planeX = (planeX + 0.12).clamp(0.08, 0.92);
      }
    });
  }

  @override
  void dispose() {
    _ticker.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: LayoutBuilder(
        builder: (context, constraints) {
          screenSize = Size(constraints.maxWidth, constraints.maxHeight);
          return GestureDetector(
            onHorizontalDragUpdate: _onDragUpdate,
            onTapDown: _onTapDown,
            child: Stack(
              children: [
                Container(
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [Color(0xFF0B1E3D), Color(0xFF2E6FBE)],
                    ),
                  ),
                ),
                CustomPaint(
                  size: Size(constraints.maxWidth, constraints.maxHeight),
                  painter: _GamePainter(
                    obstacles: obstacles,
                    planeX: planeX,
                    planeWidth: planeWidth,
                  ),
                ),
                Positioned(
                  top: 40,
                  left: 0,
                  right: 0,
                  child: Center(
                    child: Text(
                      '$score',
                      style: const TextStyle(
                        fontSize: 40,
                        fontWeight: FontWeight.bold,
                        color: Colors.white,
                      ),
                    ),
                  ),
                ),
                if (!started)
                  Positioned.fill(
                    child: Container(
                      color: Colors.black45,
                      child: const Center(
                        child: Text(
                          'برای شروع لمس کن',
                          style: TextStyle(fontSize: 24, color: Colors.white),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _GamePainter extends CustomPainter {
  final List<Obstacle> obstacles;
  final double planeX;
  final double planeWidth;

  _GamePainter({
    required this.obstacles,
    required this.planeX,
    required this.planeWidth,
  });

  @override
  void paint(Canvas canvas, Size size) {
    // obstacles
    final obstaclePaint = Paint()..color = Colors.redAccent;
    for (final o in obstacles) {
      final cx = o.x * size.width;
      canvas.drawCircle(Offset(cx, o.y), o.size / 2.4, obstaclePaint);
      final ring = Paint()
        ..color = Colors.red.shade900
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3;
      canvas.drawCircle(Offset(cx, o.y), o.size / 2.4, ring);
    }

    // plane
    final planeCx = planeX * size.width;
    final planeCy = size.height - 140;
    final w = planeWidth * size.width;
    final h = w * 1.4;

    final planePaint = Paint()..color = Colors.white;
    final path = Path();
    path.moveTo(planeCx, planeCy - h / 2);
    path.lineTo(planeCx - w / 2, planeCy + h / 2);
    path.lineTo(planeCx, planeCy + h / 4);
    path.lineTo(planeCx + w / 2, planeCy + h / 2);
    path.close();
    canvas.drawPath(path, planePaint);

    final stripePaint = Paint()..color = Colors.orangeAccent;
    canvas.drawRect(
      Rect.fromCenter(
        center: Offset(planeCx, planeCy + h * 0.05),
        width: w * 0.25,
        height: h * 0.5,
      ),
      stripePaint,
    );
  }

  @override
  bool shouldRepaint(covariant _GamePainter oldDelegate) => true;
}
