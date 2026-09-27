import 'package:flutter_test/flutter_test.dart';

import 'package:plane_dodge/main.dart';

void main() {
  testWidgets('Home screen shows play button', (WidgetTester tester) async {
    await tester.pumpWidget(const PlaneDodgeApp());

    expect(find.text('شروع بازی'), findsOneWidget);
  });
}
