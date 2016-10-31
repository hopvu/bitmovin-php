<?php


namespace Bitmovin;


use Bitmovin\api\ApiClient;
use Bitmovin\api\container\CodecConfigContainer;
use Bitmovin\api\container\EncodingContainer;
use Bitmovin\api\container\JobContainer;
use Bitmovin\api\enum\AclPermission;
use Bitmovin\api\enum\SelectionMode;
use Bitmovin\api\enum\Status;
use Bitmovin\api\factories\manifest\DashManifestFactory;
use Bitmovin\api\factories\manifest\DashProtectedManifestFactory;
use Bitmovin\api\factories\manifest\HlsManifestFactory;
use Bitmovin\api\factories\muxing\MuxingFactory;
use Bitmovin\api\model\codecConfigurations\AACAudioCodecConfiguration;
use Bitmovin\api\model\codecConfigurations\CodecConfiguration;
use Bitmovin\api\model\codecConfigurations\H264VideoCodecConfiguration;
use Bitmovin\api\model\encodings\Encoding;
use Bitmovin\api\model\encodings\helper\Acl;
use Bitmovin\api\model\encodings\helper\EncodingOutput;
use Bitmovin\api\model\encodings\helper\InputStream;
use Bitmovin\api\model\encodings\streams\Stream;
use Bitmovin\api\model\inputs\Input;
use Bitmovin\api\model\inputs\InputConverterFactory;
use Bitmovin\api\model\manifests\dash\DashManifest;
use Bitmovin\api\model\manifests\dash\Period;
use Bitmovin\api\model\manifests\hls\HlsManifest;
use Bitmovin\api\model\outputs\OutputConverterFactory;
use Bitmovin\configs\AbstractStreamConfig;
use Bitmovin\configs\audio\AudioStreamConfig;
use Bitmovin\configs\JobConfig;
use Bitmovin\configs\manifest\DashOutputFormat;
use Bitmovin\configs\manifest\HlsOutputFormat;
use Bitmovin\configs\video\H264VideoStreamConfig;
use Bitmovin\input\HttpInput;
use Bitmovin\output\GcsOutput;
use Icecave\Parity\Parity;

class BitmovinClient
{
    /**
     * @var string
     */
    private $apiKey;

    /** @var  ApiClient */
    private $apiClient;

    /**
     * BitmovinClient constructor.
     * @param string $apiKey
     */
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
        $this->apiClient = new ApiClient($apiKey);
    }

    /**
     * @param JobContainer $jobContainer
     *
     */
    private function convertInputsToEncodingContainer(JobContainer $jobContainer)
    {
        $jobContainer->encodingContainers = array();

        $streams = array_merge($jobContainer->job->encodingProfile->videoStreamConfigs,
            $jobContainer->job->encodingProfile->audioStreamConfigs);

        /** @var AbstractStreamConfig $stream */
        foreach ($streams as $stream)
        {
            $convertedInput = null;
            if ($stream->input instanceof HttpInput)
            {
                $convertedInput = InputConverterFactory::createFromHttpInput($stream->input);
            }
            if ($convertedInput == null)
            {
                continue;
            }
            $item = null;
            /** @var EncodingContainer $encodingContainer */
            foreach ($jobContainer->encodingContainers as $encodingContainer)
            {
                if ($encodingContainer->input instanceof $stream->input &&
                    Parity::isEqualTo($encodingContainer->input, $stream->input)
                )
                {
                    $item = $encodingContainer;
                    break;
                }
            }
            if ($item == null)
            {
                $item = new EncodingContainer($convertedInput, $stream->input);
                $jobContainer->encodingContainers[] = $item;
            }
            $codecConfigContainer = new CodecConfigContainer();
            $codecConfigContainer->codecConfig = $stream;
            $item->codecConfigContainer[] = $codecConfigContainer;
        }
    }

    /**
     * @param JobContainer $jobContainer
     */
    private function createInputs(JobContainer $jobContainer)
    {
        /** @var Input[] $inputs */
        $inputs = array();
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            $in_array = false;
            $apiInput = $encodingContainer->apiInput;
            foreach ($inputs as $input)
            {
                if ($input->equals($apiInput))
                {
                    $in_array = true;
                    break;
                }
            }
            if ($in_array)
            {
                continue;
            }
            $encodingContainer->apiInput = $this->apiClient->inputs()->create($apiInput);
        }
    }

    /**
     * @param JobContainer $jobContainer
     */
    private function createOutput(JobContainer $jobContainer)
    {
        $output = $jobContainer->job->output;
        if ($output instanceof GcsOutput)
        {
            $jobContainer->apiOutput = $this->apiClient->outputs()->create(OutputConverterFactory::createFromGcsOutput($output));
        }
    }

    /**
     * @param JobContainer $jobContainer
     */
    private function createEncodings(JobContainer $jobContainer)
    {
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            $encoding = new Encoding($jobContainer->job->encodingProfile->name);
            $encoding->setCloudRegion($jobContainer->job->encodingProfile->cloudRegion);
            $encoding->setDescription($jobContainer->job->encodingProfile->name);
            $encodingContainer->encoding = $this->apiClient->encodings()->create($encoding);
        }
    }

    /**
     * @param JobContainer $jobContainer
     */
    private function createConfigurations(JobContainer $jobContainer)
    {
        // Create H264 configurations
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            foreach ($encodingContainer->codecConfigContainer as &$codecConfigContainer)
            {
                if ($codecConfigContainer->codecConfig instanceof H264VideoStreamConfig)
                {
                    /**
                     * @var H264VideoCodecConfiguration
                     */
                    $codec = $codecConfigContainer->codecConfig;
                    $name = $jobContainer->job->encodingProfile->name . '_H264_' .
                        $codec->bitrate . '_' . $codec->width;
                    $config = new H264VideoCodecConfiguration($name, $codec->profile, $codec->bitrate, $codec->rate);
                    $config->setDescription($name);
                    $config->setWidth($codec->width);
                    $config->setHeight($codec->height);
                    $codecConfigContainer->apiCodecConfiguration = $this->apiClient->codecConfigurations()->videoH264()->create($config);
                }
                if ($codecConfigContainer->codecConfig instanceof AudioStreamConfig)
                {
                    /**
                     * @var AudioStreamConfig
                     */
                    $codec = $codecConfigContainer->codecConfig;
                    $name = $jobContainer->job->encodingProfile->name . '_AAC_' .
                        $codec->bitrate . '_' . $codec->rate;
                    $config = new AACAudioCodecConfiguration($name, $codec->bitrate, $codec->rate);
                    $config->setDescription($name);
                    $codecConfigContainer->apiCodecConfiguration = $this->apiClient->codecConfigurations()->audioAAC()->create($config);
                }
            }
        }
    }

    /**
     * @param Encoding           $encoding
     * @param Input              $input
     * @param string             $inputPath
     * @param int                $position
     * @param CodecConfiguration $codecConfiguration
     * @param string             $selectionMode
     * @return Stream
     */
    private function createStream(Encoding $encoding, Input $input, $inputPath, $position, CodecConfiguration $codecConfiguration, $selectionMode)
    {
        $inputStream = new InputStream($input, $inputPath, $selectionMode);
        $inputStream->setPosition($position);

        $stream = new Stream($codecConfiguration, [$inputStream]);

        return $this->apiClient->encodings()->streams($encoding)->create($stream);
    }

    private function createStreams(JobContainer $jobContainer)
    {
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            foreach ($encodingContainer->codecConfigContainer as &$codecConfigContainer)
            {
                // Create H264 configurations
                if ($codecConfigContainer->apiCodecConfiguration instanceof H264VideoCodecConfiguration)
                {
                    /**
                     * @var H264VideoCodecConfiguration
                     */
                    $codec = $codecConfigContainer->apiCodecConfiguration;
                    $codecConfigContainer->stream = $this->createStream($encodingContainer->encoding, $encodingContainer->apiInput,
                        $encodingContainer->getInputPath(), $codecConfigContainer->codecConfig->position, $codec, SelectionMode::POSITION_ABSOLUTE);
                }
                // Create AAC configurations
                if ($codecConfigContainer->apiCodecConfiguration instanceof AACAudioCodecConfiguration)
                {
                    /**
                     * @var AACAudioCodecConfiguration
                     */
                    $codec = $codecConfigContainer->apiCodecConfiguration;
                    $codecConfigContainer->stream = $this->createStream($encodingContainer->encoding, $encodingContainer->apiInput,
                        $encodingContainer->getInputPath(), $codecConfigContainer->codecConfig->position, $codec, SelectionMode::POSITION_ABSOLUTE);
                }
            }
        }
    }

    private function createMuxings(JobContainer $jobContainer)
    {
        /** @var DashOutputFormat $dashOutputFormat */
        $dashOutputFormat = null;

        /** @var HlsOutputFormat $hlsOutputFormat */
        $hlsOutputFormat = null;
        foreach ($jobContainer->job->outputFormat as $format)
        {
            if ($format instanceof DashOutputFormat)
            {
                $dashOutputFormat = $format;
            }
            if ($format instanceof HlsOutputFormat)
            {
                $hlsOutputFormat = $format;
            }
        }
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            MuxingFactory::createMuxingForEncoding($jobContainer, $encodingContainer, $dashOutputFormat, $hlsOutputFormat, $this->apiClient);
        }
    }

    private function startEncodings(JobContainer $jobContainer)
    {
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            $this->apiClient->encodings()->start($encodingContainer->encoding);
        }
    }

    public function updateEncodingJobStatus(JobContainer $jobContainer)
    {
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            $status = $this->apiClient->encodings()->status($encodingContainer->encoding);
            $encodingContainer->status = $status->getStatus();
        }
    }

    public function waitForJobsToFinish(JobContainer $jobContainer)
    {
        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            $status = null;
            while (true)
            {
                $status = $this->apiClient->encodings()->status($encodingContainer->encoding);
                $encodingContainer->status = $status->getStatus();
                if ($status->getStatus() == Status::ERROR || $status->getStatus() == Status::FINISHED)
                {
                    break;
                }
                sleep(1);
            }
        }
    }

    private function createDashManifestItem($name, EncodingOutput $output)
    {
        $manifest = new DashManifest();
        $manifest->setName($name);
        $manifest->setOutputs([$output]);
        return $this->apiClient->manifests()->dash()->create($manifest);
    }

    private function addPeriodToDashManifest($start, $duration, DashManifest $manifest)
    {
        $period = new Period();
        $period->setStart($start);
        $period->setDuration($duration);
        return $this->apiClient->manifests()->dash()->createPeriod($manifest, $period);
    }

    /**
     * @param JobContainer $jobContainer
     * @return string
     */
    public function createDashManifest(JobContainer $jobContainer)
    {

        /** @var DashOutputFormat $dash */
        $dash = null;
        foreach ($jobContainer->job->outputFormat as &$format)
        {
            if ($format instanceof DashOutputFormat)
            {
                $dash = $format;
                break;
            }
        }
        if ($dash == null)
        {
            return Status::ERROR;
        }

        $manifestOutput = new EncodingOutput($jobContainer->apiOutput);
        $manifestOutput->setOutputPath($jobContainer->getOutputPath());
        $acl = new Acl(AclPermission::ACL_PUBLIC_READ);
        $manifestOutput->setAcl([$acl]);

        $manifest = $this->createDashManifestItem("stream.mpd", $manifestOutput);
        $period = $this->addPeriodToDashManifest("0", null, $manifest);

        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            if ($dash->cenc != null)
            {
                DashProtectedManifestFactory::createDashManifestForEncoding($jobContainer, $encodingContainer, $manifest, $period, $this->apiClient);
            }
            else
            {
                DashManifestFactory::createDashManifestForEncoding($jobContainer, $encodingContainer, $manifest, $period, $this->apiClient);
            }
        }
        $this->runDashCreation($manifest, $dash);
        return $dash->status;
    }

    private function createHlsManifestItem($name, EncodingOutput $output)
    {
        $manifest = new HlsManifest();
        $manifest->setName($name);
        $manifest->setOutputs([$output]);
        return $this->apiClient->manifests()->hls()->create($manifest);
    }

    /**
     * @param JobContainer $jobContainer
     * @return string
     */
    public function createHlsManifest(JobContainer $jobContainer)
    {
        $hlsFormat = null;
        foreach ($jobContainer->job->outputFormat as &$format)
        {
            if ($format instanceof HlsOutputFormat)
            {
                $hlsFormat = $format;
                break;
            }
        }
        if ($hlsFormat == null)
        {
            return Status::ERROR;
        }

        $manifestOutput = new EncodingOutput($jobContainer->apiOutput);
        $manifestOutput->setOutputPath($jobContainer->getOutputPath());
        $acl = new Acl(AclPermission::ACL_PUBLIC_READ);
        $manifestOutput->setAcl([$acl]);

        $manifest = $this->createHlsManifestItem("stream.m3u8", $manifestOutput);

        foreach ($jobContainer->encodingContainers as &$encodingContainer)
        {
            HlsManifestFactory::createHlsManifestForEncoding($jobContainer, $encodingContainer, $manifest, $this->apiClient);
        }

        $this->runHlsCreation($manifest, $hlsFormat);
        return $hlsFormat->status;
    }

    private function runDashCreation(DashManifest $manifest, DashOutputFormat $dashOutputFormat)
    {
        $status = null;
        $this->apiClient->manifests()->dash()->start($manifest);
        while (true)
        {
            $status = $this->apiClient->manifests()->dash()->status($manifest);
            $dashOutputFormat->status = $status->getStatus();
            if ($status->getStatus() == Status::ERROR || $status->getStatus() == Status::FINISHED)
            {
                return;
            }
            sleep(1);
        }
    }

    private function runHlsCreation(HlsManifest $manifest, HlsOutputFormat $hlsOutputFormat)
    {
        $status = null;
        $this->apiClient->manifests()->hls()->start($manifest);
        while (true)
        {
            $status = $this->apiClient->manifests()->hls()->status($manifest);
            $hlsOutputFormat->status = $status->getStatus();
            if ($status->getStatus() == Status::ERROR || $status->getStatus() == Status::FINISHED)
            {
                return;
            }
            sleep(1);
        }
    }

    /**
     * @param JobConfig $job
     * @return string
     */
    public function runJobAndWaitForCompletion(JobConfig $job)
    {
        $jobContainer = $this->startJob($job);
        $this->waitForJobsToFinish($jobContainer);
        $this->createDashManifest($jobContainer);
        $this->createHlsManifest($jobContainer);
        foreach ($jobContainer->encodingContainers as $encodingContainer)
        {
            if ($encodingContainer->status != Status::FINISHED)
            {
                return Status::ERROR;
            }
        }
        foreach ($jobContainer->job->outputFormat as $outputFormat)
        {
            if ($outputFormat->status != Status::FINISHED)
            {
                return Status::ERROR;
            }
        }
        return Status::FINISHED;
    }

    /**
     * @param JobConfig $job
     * @return JobContainer
     */
    public function startJob(JobConfig $job)
    {
        $jobContainer = new JobContainer();
        $jobContainer->job = $job;
        $this->convertInputsToEncodingContainer($jobContainer);
        $this->createInputs($jobContainer);
        $this->createOutput($jobContainer);
        $this->createEncodings($jobContainer);
        $this->createConfigurations($jobContainer);
        $this->createStreams($jobContainer);
        $this->createMuxings($jobContainer);
        $this->startEncodings($jobContainer);
        return $jobContainer;
    }

}