#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <DHT.h>
#include <math.h>

#include "secrets.h"

const char* networks[][2] = {
  { GP_WIFI_1_SSID, GP_WIFI_1_PASSWORD },
  { GP_WIFI_2_SSID, GP_WIFI_2_PASSWORD },
  { GP_WIFI_3_SSID, GP_WIFI_3_PASSWORD },
  { GP_WIFI_4_SSID, GP_WIFI_4_PASSWORD }
};
const int NETWORK_COUNT = sizeof(networks) / sizeof(networks[0]);

const char* SUPABASE_HOST = GP_SUPABASE_HOST;
const char* SUPABASE_KEY = GP_SUPABASE_KEY;

#define PUMP_PIN     32
#define VALVE_PIN    33
#define DHT_PIN      22
#define DHT_TYPE     DHT22
#define SONAR_TRIG   13
#define SONAR_ECHO   12
#define RELAY_ON     HIGH
#define RELAY_OFF    LOW
// GPIO12 matches the documented wiring and is an ESP32 boot-strapping pin.
// Keep the AJ-SR04M ECHO line low during reset and use a divider/level shifter
// so the ESP32 input never exceeds 3.3 V (for example, 10k from ECHO to GPIO12
// and 20k from GPIO12 to GND when the sensor's ECHO high level is 5 V).
// AJ-SR04M readings inside its 20 cm blind zone are unreliable. Keep a small
// margin and mount the probe at least 5 cm above the tank rim. For the 39 cm
// tank with a maximum water depth of about half its height, these are initial
// sensor-face-to-water distances: empty at tank bottom = 44 cm; full = 24.5 cm.
// Re-measure both endpoints from the probe face after mounting and adjust them.
const float SONAR_MIN_VALID_DISTANCE_CM = 22.0;
const float TANK_EMPTY_DISTANCE_CM = 44.0;
const float TANK_FULL_DISTANCE_CM = 24.5;
const int SONAR_SAMPLE_COUNT = 5;
const int SONAR_MIN_VALID_SAMPLES = 3;

DHT dht(DHT_PIN, DHT_TYPE);
WebServer server(80);

float temperature  = 0;
float humidity     = 0;
int   waterLevel   = 0;
bool  waterLevelValid = false;
bool  pumpOn       = false;
bool  valveOn      = false;
bool  irrigationActive = false;
String lastCommand  = "";

unsigned long lastPush = 0;
unsigned long lastPoll = 0;

// Dashboard marks the controller offline after 90 seconds without a sensor post.
const unsigned long PUSH_INTERVAL = 60000;
const unsigned long POLL_INTERVAL = 3000;

void connectWiFi() {
  Serial.println("Scanning networks...");
  int found = WiFi.scanNetworks();
  for (int i = 0; i < found; i++) {
    for (int j = 0; j < NETWORK_COUNT; j++) {
      if (WiFi.SSID(i) == networks[j][0]) {
        Serial.print("Connecting to: ");
        Serial.println(networks[j][0]);
        WiFi.begin(networks[j][0], networks[j][1]);
        int tries = 0;
        while (WiFi.status() != WL_CONNECTED && tries < 20) {
          delay(500); Serial.print("."); tries++;
        }
        if (WiFi.status() == WL_CONNECTED) {
          Serial.println("\nWiFi connected!");
          Serial.print("IP: ");
          Serial.println(WiFi.localIP());
          return;
        }
      }
    }
  }
  Serial.println("No known network. Retrying in 10s...");
  delay(10000);
  connectWiFi();
}

float readSonar() {
  digitalWrite(SONAR_TRIG, LOW);
  delayMicroseconds(2);
  digitalWrite(SONAR_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(SONAR_TRIG, LOW);
  long duration = pulseIn(SONAR_ECHO, HIGH, 30000);
  if (duration == 0) { Serial.println("Sonar timeout"); return -1; }
  float distance = duration * 0.034 / 2.0;
  if (distance < SONAR_MIN_VALID_DISTANCE_CM) {
    Serial.printf("Sonar below reliable range: %.1f cm\n", distance);
    return -1;
  }
  return distance;
}

float readStableSonar() {
  float samples[SONAR_SAMPLE_COUNT];
  int validSamples = 0;
  for (int i = 0; i < SONAR_SAMPLE_COUNT; i++) {
    float distance = readSonar();
    if (distance > 0) samples[validSamples++] = distance;
    if (i + 1 < SONAR_SAMPLE_COUNT) delay(60);
  }
  if (validSamples < SONAR_MIN_VALID_SAMPLES) return -1;

  // Sort the valid readings and return the median (or middle-pair average).
  for (int i = 1; i < validSamples; i++) {
    float value = samples[i];
    int j = i - 1;
    while (j >= 0 && samples[j] > value) {
      samples[j + 1] = samples[j];
      j--;
    }
    samples[j + 1] = value;
  }
  if (validSamples % 2 == 1) return samples[validSamples / 2];
  return (samples[validSamples / 2 - 1] + samples[validSamples / 2]) / 2.0;
}

void readSensors() {
  float t = dht.readTemperature();
  float h = dht.readHumidity();
  if (!isnan(t)) temperature = t;
  if (!isnan(h)) humidity    = h;
  waterLevelValid = false;
  float dist = readStableSonar();
  if (dist > 0 && TANK_EMPTY_DISTANCE_CM > TANK_FULL_DISTANCE_CM &&
      TANK_FULL_DISTANCE_CM >= SONAR_MIN_VALID_DISTANCE_CM) {
    // A smaller sensor-to-water distance means a fuller tank.
    float percent = 100.0f * (TANK_EMPTY_DISTANCE_CM - dist)
                    / (TANK_EMPTY_DISTANCE_CM - TANK_FULL_DISTANCE_CM);
    waterLevel = (int)roundf(constrain(percent, 0.0f, 100.0f));
    waterLevelValid = true;
  }
  if (waterLevelValid) {
    Serial.printf("Temp: %.1f | Humidity: %.0f | Water: %d%% (%.1fcm)\n",
      temperature, humidity, waterLevel, dist);
  } else {
    Serial.printf("Temp: %.1f | Humidity: %.0f | Water: NO VALID READING (%.1fcm)\n",
      temperature, humidity, dist);
  }
}

void pushToSupabase() {
  if (WiFi.status() != WL_CONNECTED) return;
  WiFiClientSecure* client = new WiFiClientSecure;
  client->setInsecure();
  HTTPClient http;
  String url = "https://";
  url += SUPABASE_HOST;
  url += "/rest/v1/sensor_readings";
  http.begin(*client, url);
  http.addHeader("Content-Type",  "application/json");
  http.addHeader("apikey",        SUPABASE_KEY);
  http.addHeader("Authorization", String("Bearer ") + SUPABASE_KEY);
  http.addHeader("Prefer",        "return=minimal");
  String body = "{";
  body += "\"temperature\":" + String(temperature, 1) + ",";
  body += "\"humidity\":"    + String(humidity, 0)    + ",";
  body += "\"water_level\":";
  if (waterLevelValid) body += String(waterLevel);
  else body += "null";
  body += "}";
  int code = http.POST(body);
  Serial.print("Supabase push: HTTP ");
  Serial.println(code);
  http.end();
  delete client;
}

void applyWateringOutputs() {
  // One shared Water Now command operates the pump and common valve.
  pumpOn = irrigationActive;
  valveOn = irrigationActive;
  digitalWrite(PUMP_PIN, pumpOn ? RELAY_ON : RELAY_OFF);
  digitalWrite(VALVE_PIN, valveOn ? RELAY_ON : RELAY_OFF);
}

void applyWateringCommand(const String& payload) {
  if (payload == lastCommand) return;
  lastCommand = payload;
  if (payload.indexOf("\"ON\"") >= 0) {
    irrigationActive = true;
  } else if (payload.indexOf("\"OFF\"") >= 0) {
    irrigationActive = false;
  } else {
    return;
  }
  applyWateringOutputs();
  Serial.printf("Shared watering %s | Pump %s | Valve %s\n",
    irrigationActive ? "ON" : "OFF", pumpOn ? "ON" : "OFF", valveOn ? "ON" : "OFF");
}

void pollWateringCommand() {
  WiFiClientSecure* client = new WiFiClientSecure;
  client->setInsecure();
  HTTPClient http;
  String url = "https://";
  url += SUPABASE_HOST;
  url += "/rest/v1/device_commands?zone_id=eq.";
  url += "zone1";
  url += "&order=id.desc&limit=1&select=command,id";
  http.begin(*client, url);
  http.addHeader("apikey", SUPABASE_KEY);
  http.addHeader("Authorization", String("Bearer ") + SUPABASE_KEY);
  int code = http.GET();
  if (code == 200) {
    String payload = http.getString();
    Serial.printf("Shared watering command: %s\n", payload.c_str());
    applyWateringCommand(payload);
  } else {
    Serial.printf("Shared watering command poll failed: HTTP %d\n", code);
  }
  http.end();
  delete client;
}

void pollCommand() {
  if (WiFi.status() != WL_CONNECTED) return;
  pollWateringCommand();
}

void handleRoot() {
  String html = "<!DOCTYPE html><html><head>";
  html += "<meta name='viewport' content='width=device-width,initial-scale=1'>";
  html += "<title>GreenPrint Local</title>";
  html += "<style>body{font-family:sans-serif;max-width:400px;margin:40px auto;padding:0 20px;}";
  html += "h2{color:#10281a;}.card{background:#f3f6f0;border-radius:12px;padding:20px;margin:16px 0;}";
  html += ".btn{display:block;width:100%;padding:14px;border:none;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;margin:8px 0;}";
  html += ".btn-on{background:#10281a;color:#fff;}.btn-off{background:#fff;border:1px solid #ccc;color:#d9573f;}</style></head><body>";
  html += "<h2>GreenPrint Local</h2>";
  html += "<div class='card'><b>Temperature:</b> " + String(temperature, 1) + " C<br>";
  html += "<b>Humidity:</b> " + String(humidity, 0) + " %<br>";
  html += "<b>Water level:</b> ";
  html += waterLevelValid ? String(waterLevel) + " %" : String("No valid reading");
  html += "</div>";
  html += "<div class='card'><b>Shared watering:</b> " + String(pumpOn ? "ON" : "OFF") + "<br>";
  html += "<a href='/pump/on'><button class='btn btn-on'>Water both areas now</button></a>";
  html += "<a href='/pump/off'><button class='btn btn-off'>Stop watering</button></a></div>";
  html += "</body></html>";
  server.send(200, "text/html", html);
}

void handlePumpOn()   { irrigationActive = true;  applyWateringOutputs(); server.sendHeader("Location", "/"); server.send(303); }
void handlePumpOff()  { irrigationActive = false; applyWateringOutputs(); server.sendHeader("Location", "/"); server.send(303); }
void handleValveOn()  { irrigationActive = true;  applyWateringOutputs(); server.sendHeader("Location", "/"); server.send(303); }
void handleValveOff() { irrigationActive = false; applyWateringOutputs(); server.sendHeader("Location", "/"); server.send(303); }

void setup() {
  pinMode(PUMP_PIN,  OUTPUT);
  pinMode(VALVE_PIN, OUTPUT);
  digitalWrite(PUMP_PIN,  RELAY_OFF);
  digitalWrite(VALVE_PIN, RELAY_OFF);
  delay(1000);
  Serial.begin(115200);
  Serial.println("\nGreenPrint ESP32 — Booting...");
  dht.begin();
  pinMode(SONAR_TRIG, OUTPUT);
  pinMode(SONAR_ECHO, INPUT);
  connectWiFi();
  server.on("/",          handleRoot);
  server.on("/pump/on",   handlePumpOn);
  server.on("/pump/off",  handlePumpOff);
  server.on("/valve/on",  handleValveOn);
  server.on("/valve/off", handleValveOff);
  server.begin();
  Serial.println("Local dashboard ready.");
  readSensors();
  pushToSupabase();
}

void loop() {
  server.handleClient();
  unsigned long now = millis();
  if (now - lastPush >= PUSH_INTERVAL) { readSensors(); pushToSupabase(); lastPush = now; }
  if (now - lastPoll >= POLL_INTERVAL) { pollCommand(); lastPoll = now; }
}
