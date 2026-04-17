<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> {{ __( !empty($title) ? $title : 'tips') }} | {{ config('app.name','YOC') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body, html {
            height: 100%;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: "Microsoft YaHei", Arial, sans-serif;
            overflow: hidden;
        }

        /* 动态流动渐变背景（高级感） */
        body {
            background: linear-gradient(-45deg, #5b86e5, #764ba2, #4158d0, #9d50bb);
            background-size: 400% 400%;
            animation: bgFlow 12s ease infinite;
        }

        /* 背景流动动画 */
        @keyframes bgFlow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* 超高级玻璃态卡片 + 呼吸阴影 */
        .message-box {
            padding: 45px 55px;
            text-align: center;
            font-size: 26px;
            font-weight: 500;
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            /*border-radius: 24px;*/
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow:
                    0 15px 35px rgba(0, 0, 0, 0.15),
                    0 0 0 1px rgba(255,255,255,0.1),
                    0 0 40px rgba(120, 119, 198, 0.3);
            min-width: 340px;
            position: relative;
            animation: cardBreath 3.5s ease-in-out infinite alternate;
            transform: translateY(0);
        }

        /* 卡片轻微上浮呼吸 */
        @keyframes cardBreath {
            0% { transform: translateY(0); box-shadow: 0 15px 35px rgba(0,0,0,0.15), 0 0 40px rgba(120, 119, 198, 0.3); }
            100% { transform: translateY(-6px); box-shadow: 0 25px 45px rgba(0,0,0,0.2), 0 0 50px rgba(120, 119, 198, 0.45); }
        }

        /* 白色文字柔和发光 */
        .message-box span {
            text-shadow: 0 0 10px rgba(255,255,255,0.3), 0 0 20px rgba(255,255,255,0.1);
        }

        /* 八角闪烁边框（更精致） */
        @keyframes flash {
            0% { opacity: 0.9; }
            50% { opacity: 0.2; }
            100% { opacity: 0.9; }
        }

        .rect {
            background:
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) left top no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) left top no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) right top no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) right top no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) left bottom no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) left bottom no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) right bottom no-repeat,
                    linear-gradient(rgba(255,255,255,0.7), rgba(255,255,255,0.7)) right bottom no-repeat;
            background-size: 2px 24px, 24px 2px, 2px 24px, 24px 2px;
            animation: flash 2s infinite ease-in-out;
        }

        /* 图标微动效 + 居中 */
        .message-box img {
            filter: brightness(0) invert(1);
            width: 56px;
            height: 56px;
            margin-bottom: 20px;
            animation: iconFloat 3s ease-in-out infinite alternate;
        }

        @keyframes iconFloat {
            0% { transform: translateY(0); }
            100% { transform: translateY(-5px); }
        }
    </style>
</head>
<body>
<div class="message-box rect">
    @if(!empty($imageg))
        <img src="{{$imageg}}" alt="Icon">
    @endif
    <span>{{ __( !empty($message) ? $message : 'tips') }}</span>
</div>
</body>
</html>
