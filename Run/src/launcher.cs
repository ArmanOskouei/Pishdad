using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

// StartPishdad: one-click launcher for the Pishdad stack (backend docker + frontend node).
// C# 5 compatible (built with .NET Framework csc, no downloads needed).
class StartPishdad
{
    static string RunDir;
    static string BackendDir;
    static string FrontendDir;
    static string LogDir;
    static Process frontProc;
    static readonly object LogLock = new object();

    static int Main()
    {
        try
        {
            RunDir = AppDomain.CurrentDomain.BaseDirectory;
            string root = Path.GetFullPath(Path.Combine(RunDir, ".."));
            BackendDir = Path.Combine(root, "pishdad-core", "backend");
            FrontendDir = Path.Combine(root, "pishdad-core", "frontend");
            LogDir = Path.Combine(RunDir, "logs");
            Directory.CreateDirectory(LogDir);

            Log("Pishdad stack launcher - " + root);
            EnsureDocker();
            StartBackend();
            StartFrontend();
            OpenBrowser("http://localhost:3100/admin/login");

            Log("");
            Log("ALL UP. Admin panel opened in your browser.");
            Log("Backend : http://localhost:8080/api  |  Frontend: http://localhost:3100/admin/login");
            Log("Press ENTER to stop the frontend server and exit (Docker keeps running).");
            Console.ReadLine();
            StopFrontend();
            return 0;
        }
        catch (Exception ex)
        {
            Log("FATAL: " + ex.Message);
            Log("Press ENTER to exit.");
            Console.ReadLine();
            return 1;
        }
    }

    // ---------- docker ----------
    static void EnsureDocker()
    {
        if (RunOut("docker", "info") == 0) { Log("Docker is running."); return; }
        string dd = "C:\\Program Files\\Docker\\Docker\\Docker Desktop.exe";
        if (!File.Exists(dd)) throw new Exception("Docker Desktop not found. Install it first.");
        Log("Starting Docker Desktop (may take a minute)...");
        Process.Start(dd);
        for (int i = 0; i < 60; i++)
        {
            Thread.Sleep(3000);
            if (RunOut("docker", "info") == 0) { Log("Docker is running."); return; }
            if (i % 10 == 9) Log("... still waiting for Docker ...");
        }
        throw new Exception("Docker did not start in time.");
    }

    static void StartBackend()
    {
        Log("Backend: docker compose up -d ...");
        if (Run("docker", "compose up -d", BackendDir, true) != 0)
            throw new Exception("docker compose up failed (see output above).");
        Log("Waiting for API http://localhost:8080 ...");
        if (!WaitHttp("http://localhost:8080/api/v1/site/status", 180))
            throw new Exception("Backend API did not answer on :8080.");
        Log("Backend OK.");
    }

    // ---------- frontend ----------
    static void StartFrontend()
    {
        string serverJs = Path.Combine(FrontendDir, ".next", "standalone", "server.js");
        if (!File.Exists(serverJs))
        {
            Log("No production build found - building (one time, few minutes)...");
            if (RunOut("node", "--version") != 0) throw new Exception("Node.js not found on PATH.");
            if (Run("cmd.exe", "/c npm run build", FrontendDir, true) != 0)
                throw new Exception("npm run build failed (see output above).");
        }
        CopyStatic();

        if (HttpStatus("http://localhost:3100/admin/login") == 200)
        {
            Log("Frontend already running on :3100 - reusing it.");
            return;
        }
        FreePortIfNode(3100);

        Log("Starting frontend (production) on :3100 ...");
        string logFile = Path.Combine(LogDir, "front-" + DateTime.Now.ToString("yyyyMMdd-HHmm") + ".log");
        StreamWriter logWriter = new StreamWriter(logFile, true, Encoding.UTF8);
        logWriter.AutoFlush = true;
        ProcessStartInfo psi = new ProcessStartInfo();
        psi.FileName = "node";
        psi.Arguments = "\".next\\standalone\\server.js\"";
        psi.WorkingDirectory = FrontendDir;
        psi.UseShellExecute = false;
        psi.CreateNoWindow = true;
        psi.RedirectStandardOutput = true;
        psi.RedirectStandardError = true;
        // REVALIDATE_SECRET is a real shared secret and must never live in source:
        // anyone with this repo could POST /api/revalidate and purge the cache of
        // every install that reuses it. The backend's git-ignored .env is the
        // single source of truth; an env var wins so production / CI can override.
        string ResolveRevalidateSecret()
        {
            var fromEnv = Environment.GetEnvironmentVariable("REVALIDATE_SECRET");
            if (!string.IsNullOrWhiteSpace(fromEnv)) return fromEnv.Trim();

            var envFile = Path.Combine(BackendDir, ".env");
            if (File.Exists(envFile))
            {
                foreach (var line in File.ReadLines(envFile))
                {
                    var m = Regex.Match(line, @"^\s*REVALIDATE_SECRET\s*=\s*(\S+)");
                    if (m.Success) return m.Groups[1].Value;
                }
            }
            throw new Exception("REVALIDATE_SECRET not found. Set it in the environment or in pishdad-core/backend/.env.");
        }

        psi.EnvironmentVariables["NEXT_PUBLIC_API_URL"] = "http://localhost:8080/api";
        psi.EnvironmentVariables["INTERNAL_API_URL"] = "http://localhost:8080/api";
        psi.EnvironmentVariables["REVALIDATE_SECRET"] = ResolveRevalidateSecret();
        psi.EnvironmentVariables["PORT"] = "3100";
        psi.EnvironmentVariables["HOSTNAME"] = "0.0.0.0";
        frontProc = new Process();
        frontProc.StartInfo = psi;
        frontProc.OutputDataReceived += (s, e) => { if (e.Data != null) { lock (LogLock) { logWriter.WriteLine(e.Data); } } };
        frontProc.ErrorDataReceived += (s, e) => { if (e.Data != null) { lock (LogLock) { logWriter.WriteLine("ERR: " + e.Data); } } };
        frontProc.Start();
        frontProc.BeginOutputReadLine();
        frontProc.BeginErrorReadLine();

        Log("Waiting for http://localhost:3100/admin/login ... (log: " + logFile + ")");
        if (!WaitHttp("http://localhost:3100/admin/login", 120))
            throw new Exception("Frontend did not answer on :3100. See log: " + logFile);
        Log("Frontend OK.");
    }

    static void StopFrontend()
    {
        try
        {
            if (frontProc != null && !frontProc.HasExited)
            {
                frontProc.Kill();
                Log("Frontend server stopped.");
            }
        }
        catch { }
    }

    static void CopyStatic()
    {
        DirectoryCopy(Path.Combine(FrontendDir, ".next", "static"),
            Path.Combine(FrontendDir, ".next", "standalone", ".next", "static"));
        string pub = Path.Combine(FrontendDir, "public");
        if (Directory.Exists(pub))
            DirectoryCopy(pub, Path.Combine(FrontendDir, ".next", "standalone", "public"));
    }

    static void DirectoryCopy(string src, string dst)
    {
        if (!Directory.Exists(src)) return;
        Directory.CreateDirectory(dst);
        foreach (string f in Directory.GetFiles(src))
            File.Copy(f, Path.Combine(dst, Path.GetFileName(f)), true);
        foreach (string d in Directory.GetDirectories(src))
            DirectoryCopy(d, Path.Combine(dst, Path.GetFileName(d)));
    }

    static void FreePortIfNode(int port)
    {
        string ns = RunCapture("netstat", "-ano");
        Match m = Regex.Match(ns, @"TCP\s+\S*:" + port + @"\s+\S+\s+LISTENING\s+(\d+)");
        if (!m.Success) return;
        int pid;
        if (!int.TryParse(m.Groups[1].Value, out pid)) return;
        try
        {
            Process p = Process.GetProcessById(pid);
            if (p.ProcessName.Equals("node", StringComparison.OrdinalIgnoreCase))
            {
                p.Kill();
                Log("Killed stale node process on :" + port + ".");
                Thread.Sleep(2000);
            }
            else
            {
                throw new Exception("Port " + port + " is used by '" + p.ProcessName + "' (not node). Free it first.");
            }
        }
        catch (Exception ex)
        {
            if (ex.Message.StartsWith("Port ")) throw;
        }
    }

    static void OpenBrowser(string url)
    {
        try
        {
            ProcessStartInfo psi = new ProcessStartInfo(url);
            psi.UseShellExecute = true;
            Process.Start(psi);
            Log("Browser opened: " + url);
        }
        catch (Exception ex) { Log("Could not auto-open browser: " + ex.Message + " -> open manually: " + url); }
    }

    // ---------- helpers ----------
    static void Log(string m)
    {
        Console.WriteLine("[{0}] {1}", DateTime.Now.ToString("HH:mm:ss"), m);
    }

    static int HttpStatus(string url)
    {
        try
        {
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = "GET";
            req.Timeout = 5000;
            using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
                return (int)resp.StatusCode;
        }
        catch (WebException ex)
        {
            HttpWebResponse r = ex.Response as HttpWebResponse;
            return r != null ? (int)r.StatusCode : -1;
        }
        catch { return -1; }
    }

    static bool WaitHttp(string url, int seconds)
    {
        int tries = Math.Max(1, seconds / 3);
        for (int i = 0; i < tries; i++)
        {
            int code = HttpStatus(url);
            if (code == 200) return true;
            if (code > 0) Log("... " + url + " -> HTTP " + code + " (retrying)");
            Thread.Sleep(3000);
        }
        return HttpStatus(url) == 200;
    }

    static int Run(string file, string args, string workDir, bool showOutput)
    {
        ProcessStartInfo psi = new ProcessStartInfo(file, args);
        psi.WorkingDirectory = workDir;
        psi.UseShellExecute = false;
        psi.CreateNoWindow = true;
        if (!showOutput) { psi.RedirectStandardOutput = true; psi.RedirectStandardError = true; }
        using (Process p = Process.Start(psi))
        {
            if (!showOutput) { p.StandardOutput.ReadToEnd(); p.StandardError.ReadToEnd(); }
            p.WaitForExit();
            return p.ExitCode;
        }
    }

    static int RunOut(string file, string args)
    {
        return Run(file, args, RunDir, false);
    }

    static string RunCapture(string file, string args)
    {
        ProcessStartInfo psi = new ProcessStartInfo(file, args);
        psi.WorkingDirectory = RunDir;
        psi.UseShellExecute = false;
        psi.CreateNoWindow = true;
        psi.RedirectStandardOutput = true;
        psi.StandardOutputEncoding = Encoding.UTF8;
        using (Process p = Process.Start(psi))
        {
            string o = p.StandardOutput.ReadToEnd();
            p.WaitForExit();
            return o;
        }
    }
}
