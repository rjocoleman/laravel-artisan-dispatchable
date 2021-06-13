<?php

namespace Spatie\ArtisanDispatchable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionParameter;
use Spatie\ArtisanDispatchable\Exceptions\ModelNotFound;
use Spatie\ArtisanDispatchable\Exceptions\RequiredOptionMissing;

class ArtisanJob
{
    public function __construct(protected string $jobClassName)
    {
    }

    public function getFullCommand(): string
    {
        return "{$this->getCommandName()} {$this->getOptionString()}";
    }

    protected function getCommandName(): string
    {
        $shortClassName = class_basename($this->jobClassName);

        return Str::beforeLast(Str::kebab($shortClassName), '-job');
    }

    protected function getOptionString(): string
    {
        $parameters = (new ReflectionClass($this->jobClassName))
            ->getConstructor()
            ?->getParameters();

        if (is_null($parameters)) {
            return '';
        }

        return collect($parameters)
            ->map(fn (ReflectionParameter $parameter) => $parameter->name)
            ->map(fn (string $argumentName) => '{--' . Str::camel($argumentName) . '=}')
            ->implode(' ');
    }

    public function handleCommand(ClosureCommand $command): void
    {
        $parameters = $this->constructorValues($command);

        $job = new $this->jobClassName(...$parameters);

        $job->handle();
    }

    protected function constructorValues(ClosureCommand $command): array
    {
        $parameters = (new ReflectionClass($this->jobClassName))
            ->getConstructor()
            ?->getParameters();

        if (is_null(($parameters))) {
            return [];
        }

        return collect($parameters)
            ->map(function (ReflectionParameter $parameter) use ($command) {
                $parameterName = $parameter->getName();

                $value = $command->option($parameterName);

                if (is_null($value)) {
                    throw RequiredOptionMissing::make($this->getCommandName(), $parameterName);
                }

                $parameterType = $parameter->getType()->getName();

                if (is_a($parameterType, Model::class, true)) {
                    $model = $parameterType::find($value);

                    if (is_null($model)) {
                        throw ModelNotFound::make($this->getCommandName(), $parameterName, $value);
                    }

                    $value = $model;
                }

                return $value;
            })
            ->all();
    }
}
