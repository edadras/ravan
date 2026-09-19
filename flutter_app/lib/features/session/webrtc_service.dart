import 'package:livekit_client/livekit_client.dart';

/// Wraps the SFU connection. Credentials come from POST /sessions/{uuid}/join → `webrtc`.
class WebRtcService {
  Room? room;
  LocalVideoTrack? localVideo;
  LocalAudioTrack? localAudio;

  Future<Room> connect({required String url, required String token, required bool video}) async {
    room = Room(roomOptions: const RoomOptions(adaptiveStream: true, dynacast: true, defaultVideoPublishOptions: VideoPublishOptions(simulcast: true)));
    await room!.connect(url, token);
    localAudio = await LocalAudioTrack.create(const AudioCaptureOptions(echoCancellation: true, noiseSuppression: true));
    await room!.localParticipant?.publishAudioTrack(localAudio!);
    if (video) {
      localVideo = await LocalVideoTrack.createCameraTrack(const CameraCaptureOptions(params: VideoParametersPresets.h720_169));
      await room!.localParticipant?.publishVideoTrack(localVideo!);
    }
    return room!;
  }

  Future<void> setCameraEnabled(bool on) async => room?.localParticipant?.setCameraEnabled(on);

  Future<void> disconnect() async {
    await room?.disconnect();
    await room?.dispose();
    room = null;
  }
}
